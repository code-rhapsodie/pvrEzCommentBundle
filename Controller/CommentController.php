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

namespace pvr\EzCommentBundle\Controller;

use eZ\Bundle\EzPublishCoreBundle\Controller;
use pvr\EzCommentBundle\Comment\PvrEzCommentManager;
use pvr\EzCommentBundle\Service\Comment;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatorInterface;

class CommentController extends Controller
{
    /**
     * @var Comment
     */
    private $commentService;

    /**
     * @var PvrEzCommentManager
     */
    private $commentManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var int
     */
    private $maxAge;

    public function __construct(Comment $commentService, PvrEzCommentManager $commentManager, TranslatorInterface $translator, int $maxAge)
    {
        $this->commentService = $commentService;
        $this->commentManager = $commentManager;
        $this->translator = $translator;
        $this->maxAge = $maxAge;
    }

    /**
     * List comments from a certain contentId.
     *
     * @param $contentId id from current content
     * @param $locationId
     * @param array $params
     *
     * @return Response
     */
    public function getCommentsAction(Request $request, $contentId, $locationId, $params = array())
    {
        $response = new Response();
        $response->setMaxAge($this->maxAge);
        $response->headers->set('X-Location-Id', $locationId);

        $repository = $this->container->get('ezpublish.api.repository');
        $currentUserId = $repository->getPermissionResolver()->getCurrentUserReference();
        $currentUser = $repository->getUserService()->loadUser($currentUserId->getUserId());

        $canComment = $repository->getPermissionResolver()->canUser('comment', 'add', $currentUser);

        $params += ['canComment' => $canComment];

        $data = $this->commentService->getComments($request, $contentId, $locationId);
        $data += array('params' => $params);

        $template = isset($params['template']) ? $params['template'] : 'PvrEzCommentBundle:blog:list_comments.html.twig';

        return $this->render($template, $data, $response);
    }

    /**
     * This function get comment form depends of configuration.
     *
     * @param $contentId id of content
     * @param array $params
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getFormCommentAction($contentId, $params = array())
    {
        $form = $this->commentService->generateForm();

        $template = isset($params['template']) ? $params['template'] : 'PvrEzCommentBundle:blog:form_comments.html.twig';

        return $this->render(
            $template,
            array(
                'form' => $form ? $form->createView() : null,
                'contentId' => $contentId,
                'params' => $params,
            )
        );
    }

    /**
     * Add a comment via ajax call.
     *
     * @param Request $request
     * @param $contentId id of content to insert comment
     *
     * @return Response
     */
    public function addCommentAction(Request $request, $contentId)
    {
        if ($request->isXmlHttpRequest()) {
            return $this->commentService->addComments($contentId, $request);
        }

        return new Response(
            $this->container->get('translator')->trans('Something goes wrong !'), 400
        );
    }

    /**
     * After receiving email choose if you would like to approve it or not.
     *
     * @param $contentId id of content
     * @param $sessionHash hash session do decrypt for transaction
     * @param $action approve|reject value
     * @param $commentId
     *
     * @return Response
     */
    public function commentModerateAction($contentId, $sessionHash, $action, $commentId)
    {
        $connection = $this->container->get('ezpublish.connection');

        // Check if comment has waiting status..
        $canUpdate = $this->commentManager->canUpdate($contentId, $sessionHash, $connection, $commentId);

        if ($canUpdate) {
            if ('approve' == $action) {
                // Update status
                if ($this->commentManager->updateStatus($connection, $commentId)) {
                    return new Response(
                        $this->translator->trans('Comment publish !')
                    );
                }
            } else {
                // Update status
                if ($this->commentManager->updateStatus($connection, $commentId, $this->commentManager::COMMENT_REJECTED)) {
                    return new Response(
                        $this->translator->trans('Comment rejected !')
                    );
                }
            }
        }

        return new Response(
            $this->translator
                ->trans('An unexpected error has occurred, please contact the webmaster !'),
            406
        );
    }
}
