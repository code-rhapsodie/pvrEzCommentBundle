<?php

declare(strict_types=1);

/*
 * This file is part of the pvrEzComment package.
 *
 * (c) Philippe Vincent-Royol <vincent.royol@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace pvr\EzCommentBundle\Comment;

use Doctrine\DBAL\Connection;
use eZ\Publish\Core\MVC\Symfony\Locale\LocaleConverter;
use eZ\Publish\Core\MVC\Symfony\Routing\ChainRouter;
use eZ\Publish\Core\Repository\Values\User\User as EzUser;
use eZ\Publish\Core\REST\Common\Exceptions\NotFoundException;
use pvr\EzCommentBundle\Form\AnonymousCommentType;
use pvr\EzCommentBundle\Form\ConnectedCommentType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;

class PvrEzCommentManager implements PvrEzCommentManagerInterface
{
    const COMMENT_WAITING = 0;
    const COMMENT_ACCEPT = 1;
    const COMMENT_REJECTED = 2;
    const ANONYMOUS_USER = 10;

    protected $anonymous_access;
    protected $moderating;
    protected $comment_reply;
    protected $moderate_subject;
    protected $moderate_from;
    protected $moderate_to;
    protected $moderate_template;
    protected $isNotify;
    /** @var $formFactory \Symfony\Component\Form\FormFactory */
    protected $formFactory;
    protected $twig;
    protected $mailer;
    protected $encryption;
    protected $router;
    protected $connection;

    public function __construct(
        $config,
        \Swift_Mailer $mailer,
        PvrEzCommentEncryption $encryption,
        ChainRouter $route,
        Connection $connection
    ) {
        $this->anonymous_access = $config['anonymous'];
        $this->moderating = $config['moderating'];
        $this->comment_reply = $config['comment_reply'];
        $this->moderate_subject = $config['moderate_subject'];
        $this->moderate_from = $config['moderate_from'];
        $this->moderate_to = $config['moderate_to'];
        $this->moderate_template = $config['moderate_template'];
        $this->isNotify = $config['notify_enabled'];
        $this->mailer = $mailer;
        $this->encryption = $encryption;
        $this->router = $route;
        $this->connection = $connection;
    }

    /**
     * @param FormFactory $form
     */
    public function setFormFactory(FormFactory $form)
    {
        $this->formFactory = $form;
    }

    public function setTwig(\Twig_Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Get list of comments depending of contentId and status.
     *
     * @param int   $contentId      Get content Id to fetch comments
     * @param array $viewParameters
     * @param int   $status
     *
     * @return mixed Array or false
     */
    public function getComments(int $contentId, array $viewParameters = array(), int $status = self::COMMENT_ACCEPT)
    {
        $selectQuery = $this->connection->createQueryBuilder();

        $column = 'created';
        $sort = 'DESC';

        // Configure how to sort things
        if (!empty($viewParameters)) {
            if ('author' == $viewParameters['cSort']) {
                $column = 'name';
            }
            if ('asc' == $viewParameters['cOrder']) {
                $sort = 'ASC';
            }
        }

        //Get Parents Comments
        $selectQuery
            ->select('id', 'created', 'user_id', 'name', 'email', 'url', 'text', 'title', 'parent_comment_id')
            ->from('ezcomment')
            ->where(
                $selectQuery->expr()->eq(
                    'contentobject_id',
                    $selectQuery->createNamedParameter($contentId, \PDO::PARAM_INT)
                )
            )->andWhere(
                $selectQuery->expr()->eq(
                    'status',
                    $selectQuery->createNamedParameter($status, \PDO::PARAM_INT)
                )
            )->andWhere(
                $selectQuery->expr()->eq(
                    'parent_comment_id',
                    $selectQuery->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )->orderBy($column, $sort);
        $statement = $selectQuery->execute();

        $comments = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if ($this->comment_reply) {
            //Get Childs Comments
            $selectQuery = $this->connection->createQueryBuilder();
            $selectQuery->select('id', 'created', 'user_id', 'name', 'email', 'url', 'text', 'title',
                'parent_comment_id')
                ->from('ezcomment')
                ->where(
                    $selectQuery->expr()->eq(
                        'contentobject_id',
                        $selectQuery->createNamedParameter($contentId, \PDO::PARAM_INT)
                    )
                )->andWhere(
                    $selectQuery->expr()->eq(
                        'status',
                        $selectQuery->createNamedParameter($status, \PDO::PARAM_INT)
                    )
                )->andWhere(
                    $selectQuery->expr()->neq(
                        'parent_comment_id',
                        $selectQuery->createNamedParameter(0, \PDO::PARAM_INT)
                    )
                )
                ->orderBy($column, $sort);
            $statement = $selectQuery->execute();

            $childs = $statement->fetchAll(\PDO::FETCH_ASSOC);

            for ($i = 0; $i < count($comments); ++$i) {
                for ($j = 0; $j < count($childs); ++$j) {
                    if ($comments[$i]['id'] == $childs[$j]['parent_comment_id']) {
                        $comments[$i]['children'][] = $childs[$j];
                    }
                }
            }
        }

        return $comments;
    }

    /**
     * Add a comment via an ezuser.
     *
     * @param Request         $request
     * @param EzUser          $currentUser
     * @param LocaleConverter $localeService
     * @param array           $data
     * @param null            $contentId
     * @param null            $sessionId
     */
    public function addComment(
        Request $request,
        EzUser $currentUser,
        LocaleConverter $localeService,
        $data = array(),
        $contentId = null,
        $sessionId = null
    ) {
        $languageCode = $localeService->convertToEz($request->getLocale());

        $created = $modified = \time();
        $status = $this->hasModeration() ? self::COMMENT_WAITING : self::COMMENT_ACCEPT;
        $parentCommentId = $data['parent_comment_id'];

        $this->connection->insert('ezcomment', [
            'language_id' => $this->getLanguageId($languageCode),
            'created' => $created,
            'modified' => $modified,
            'user_id' => $currentUser->versionInfo->contentInfo->id,
            'session_key' => $sessionId,
            'ip' => $request->getClientIp(),
            'contentobject_id' => $contentId,
            'parent_comment_id' => $parentCommentId,
            'name' => $currentUser->versionInfo->contentInfo->name,
            'email' => $currentUser->email,
            'url' => '',
            'text' => $data['message'],
            'status' => $status,
            'title' => '',
        ]);

        return $this->connection->lastInsertId();
    }

    /**
     * Add an anonymous comment.
     *
     * @param Request         $request
     * @param LocaleConverter $localeService
     * @param array           $data
     * @param $contentId
     * @param null $sessionId
     */
    public function addAnonymousComment(
        Request $request,
        LocaleConverter $localeService,
        array $data,
        $contentId,
        $sessionId = null
    ) {
        $languageCode = $localeService->convertToEz($request->getLocale());
        $languageId = $this->getLanguageId($languageCode);

        $created = $modified = \time();
        $userId = self::ANONYMOUS_USER;
        $sessionKey = $sessionId;
        $ip = $request->getClientIp();
        $parentCommentId = 0;
        $name = $data['name'];
        $email = $data['email'];
        $url = '';
        $text = $data['message'];
        $status = $this->hasModeration() ? self::COMMENT_WAITING : self::COMMENT_ACCEPT;
        $title = '';

        $this->connection->insert('ezcomment', [
            'language_id' => $languageId,
            'created' => $created,
            'modified' => $modified,
            'user_id' => $userId,
            'session_key' => $sessionKey,
            'ip' => $ip,
            'contentobject_id' => $contentId,
            'parent_comment_id' => $parentCommentId,
            'name' => $name,
            'email' => $email,
            'url' => $url,
            'text' => $text,
            'status' => $status,
            'title' => $title,
        ]);

        return $this->connection->lastInsertId();
    }

    /**
     * Create an anonymous form.
     *
     * @return mixed
     */
    public function createAnonymousForm()
    {
        return $this->formFactory->createBuilder(AnonymousCommentType::class)->getForm();
    }

    /**
     * Create an ezuser form.
     *
     * @return mixed
     */
    public function createUserForm()
    {
        return $this->formFactory->createBuilder(ConnectedCommentType::class)->getForm();
    }

    /**
     * Get validation error from form.
     *
     * @param \Symfony\Component\Form\Form $form the form
     *
     * @return array errors messages
     */
    public function getErrorMessages(Form $form)
    {
        $errors = array();
        foreach ($form->getErrors() as $key => $error) {
            $template = $error->getMessageTemplate();
            $parameters = $error->getMessageParameters();

            foreach ($parameters as $var => $value) {
                $template = str_replace($var, $value, $template);
            }

            $errors[$key] = $template;
        }
        foreach ($form->all() as $key => $child) {
            /** @var $child Form */
            if ($err = $this->getErrorMessages($child)) {
                $errors[$key] = $err;
            }
        }

        return $errors;
    }

    /**
     * Send message to admin(s).
     */
    public function sendMessage($data, $user, $contentId, $sessionId, $commentId)
    {
        if (null === $user) {
            $name = $data['name'];
            $email = $data['email'];
        } else {
            $name = $user->versionInfo->contentInfo->name;
            $email = $user->email;
        }

        $encodeSession = $this->encryption->encode($sessionId);

        $approve_url = $this->router->generate(
            'pvrezcomment_moderation',
            array(
                'contentId' => $contentId,
                'sessionHash' => $encodeSession,
                'action' => 'approve',
                'commentId' => $commentId,
            ),
            true
        );
        $reject_url = $this->router->generate(
            'pvrezcomment_moderation',
            array(
                'contentId' => $contentId,
                'sessionHash' => $encodeSession,
                'action' => 'reject',
                'commentId' => $commentId,
            ),
            true
        );

        $message = \Swift_Message::newInstance()
            ->setSubject($this->moderate_subject)
            ->setFrom($this->moderate_from)
            ->setTo($this->moderate_to)
            ->setBody(
                $this->twig->render($this->moderate_template, array(
                    'name' => $name,
                    'email' => $email,
                    'comment' => $data['message'],
                    'approve_url' => $approve_url,
                    'reject_url' => $reject_url,
                ))
            );
        $this->mailer->send($message);
    }

    /**
     * Check if status of comment is on waiting.
     *
     * @param $contentId
     * @param $sessionHash
     *
     * @return bool
     */
    public function canUpdate($contentId, $sessionHash, $commentId)
    {
        $session_id = $this->encryption->decode($sessionHash);

        $selectQuery = $this->connection->createQueryBuilder();

        $selectQuery->select(
            'id'
        )->from(
            'ezcomment'
        )->where(
            $selectQuery->expr()->eq(
                'contentobject_id',
                $selectQuery->createNamedParameter($contentId, \PDO::PARAM_INT)
            )
        )->andWhere(
            $selectQuery->expr()->eq(
                'session_key',
                $selectQuery->createNamedParameter($session_id, \PDO::PARAM_INT)
            )
        )->andWhere(
            $selectQuery->expr()->eq(
                'status',
                $selectQuery->createNamedParameter(self::COMMENT_WAITING, \PDO::PARAM_INT)
            )
        )->andWhere(
            $selectQuery->expr()->eq(
                'id',
                $selectQuery->createNamedParameter($commentId, \PDO::PARAM_INT)
            )
        );
        $statement = $selectQuery->execute();

        $row = $statement->fetch();

        return false !== $row ? true : false;
    }

    /**
     * Update status of a comment.
     *
     * @param int $commentId
     * @param int $status
     *
     * @return mixed
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function updateStatus($commentId, $status = self::COMMENT_ACCEPT)
    {
        return $this->connection->update('ezcomment',
            ['status' => $status],
            ['id' => $commentId, 'status' => self::COMMENT_WAITING],
            ['id' => \PDO::PARAM_INT, 'status' => \PDO::PARAM_INT]
        );
    }

    /**
     * @param int $commentId
     * @param int $status
     *
     * @return mixed
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function updateStatusFromUI($commentId, $status = self::COMMENT_REJECTED)
    {
        return $this->connection->update('ezcomment',
            ['status' => $status],
            ['id' => $commentId],
            ['id' => \PDO::PARAM_INT, 'status' => \PDO::PARAM_INT]
        );
    }

    /**
     * @param bool|int $contentId
     * @param int      $status
     *
     * @return int
     */
    public function getCountComments($contentId = false, $status = -1)
    {
        $selectQuery = $this->connection->createQueryBuilder();
        $selectQuery->select('*')->from('ezcomment');

        if ($contentId) {
            $selectQuery->andWhere($selectQuery->expr()->eq(
                'contentobject_id',
                $selectQuery->createNamedParameter($contentId, \PDO::PARAM_INT)
            ));
        }

        if ($status && -1 != $status) {
            $selectQuery->andWhere($selectQuery->expr()->eq(
                'status',
                $selectQuery->createNamedParameter($status, \PDO::PARAM_INT)
            ));
        }

        $statement = $selectQuery->execute();

        return $statement->rowCount();
    }

    /**
     * @return bool
     */
    public function canReply()
    {
        return $this->comment_reply;
    }

    /**
     * @return bool
     */
    public function hasAnonymousAccess()
    {
        return $this->anonymous_access;
    }

    /**
     * @return bool
     */
    public function hasModeration()
    {
        return $this->moderating;
    }

    /**
     * Get list of last comments.
     *
     * @param int $limit
     * @param int $offset
     * @param int $status
     *
     * @return mixed Array or false
     *
     * @internal param bool $onlyAccept
     */
    public function getLastComments($limit = 5, $offset = 0, $status = -1)
    {
        $selectQuery = $this->connection->createQueryBuilder();

        $column = 'created';
        $sort = 'DESC';

        $selectQuery->select(
            'id',
            'created',
            'contentobject_id',
            'user_id',
            'name',
            'email',
            'url',
            'text',
            'title',
            'status'
        )->from(
            'ezcomment'
        );

        // Filter only by accept comment ...
        if (-1 !== $status) {
            $selectQuery->where(
                $selectQuery->expr()->eq(
                    'status',
                    $selectQuery->createNamedParameter($status, \PDO::PARAM_INT)
                )
            );
        }

        $selectQuery->orderBy($column, $sort)->setMaxResults($limit)->setFirstResult((int) $offset);

        $statement = $selectQuery->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get list of last comments.
     *
     * @param $userId
     * @param int $limit
     *
     * @return mixed Array or false
     */
    public function getLastCommentsByUser($userId, $limit = 5)
    {
        $selectQuery = $this->connection->createQueryBuilder();

        $column = 'created';
        $sort = 'DESC';

        $selectQuery
            ->select('id', 'created', 'contentobject_id', 'user_id', 'name', 'email', 'url', 'text', 'title')
            ->from('ezcomment')
            ->where(
                $selectQuery->expr()->eq(
                    'status',
                    $selectQuery->createNamedParameter(self::COMMENT_ACCEPT, \PDO::PARAM_INT)
                )
            )->andWhere(
                $selectQuery->expr()->eq(
                    'user_id',
                    $selectQuery->createNamedParameter($userId, \PDO::PARAM_INT)
                )
            )->orderBy($column, $sort)
            ->setMaxResults($limit);

        $statement = $selectQuery->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get ezcontent_language Id.
     *
     * @param $languageCode
     *
     * @return int
     */
    protected function getLanguageId($languageCode)
    {
        $selectQuery = $this->connection->createQueryBuilder();

        $selectQuery->select(
            'id'
        )->from(
            'ezcontent_language'
        )->where(
            $selectQuery->expr()->eq(
                'locale',
                $selectQuery->createNamedParameter($languageCode, \PDO::PARAM_STR)
            )
        );
        $statement = $selectQuery->execute();

        $row = $statement->fetch();
        if (isset($row['id'])) {
            return $row['id'];
        }

        return 0;
    }

    /**
     * Check if status exists.
     *
     * @param $status
     *
     * @return bool
     */
    public function statusExists($status)
    {
        return in_array($status, [
            self::COMMENT_WAITING,
            self::COMMENT_ACCEPT,
            self::COMMENT_REJECTED,
        ]);
    }

    /**
     * @param $commentId
     *
     * @return bool
     */
    public function commentExists($commentId)
    {
        $selectQuery = $this->connection->createQueryBuilder();
        $selectQuery->select('*')
            ->from('ezcomment')
            ->where($selectQuery->expr()->eq(
                'id',
                $selectQuery->createNamedParameter($commentId, \PDO::PARAM_INT)
            ));
        $statement = $selectQuery->execute();

        return $statement->rowCount() > 0 ? true : false;
    }

    /**
     * @param int $commentId
     *
     * @throws NotFoundException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Exception\InvalidArgumentException
     */
    public function deleteById($commentId)
    {
        if (!$this->commentExists($commentId)) {
            throw new NotFoundException("Comment with Id $commentId not exists ! ");
        }

        $this->connection->delete('ezcomment', ['id' => $commentId], ['id' => \PDO::PARAM_INT]);
    }
}
