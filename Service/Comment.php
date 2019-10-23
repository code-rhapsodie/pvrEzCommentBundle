<?php

declare(strict_types=1);

namespace pvr\EzCommentBundle\Service;

use eZ\Publish\API\Repository\Repository;
use eZ\Publish\Core\MVC\Symfony\Locale\LocaleConverter;
use pvr\EzCommentBundle\Comment\PvrEzCommentManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatorInterface;

class Comment
{
    protected $commentManager;
    protected $connection;
    protected $locale;
    protected $translator;
    protected $repository;
    /**
     * @var AuthorizationCheckerInterface
     */
    protected $security;

    /**
     * @param PvrEzCommentManager $commentManager
     * @param $connection
     * @param LocaleConverter     $locale
     * @param TranslatorInterface $translator
     * @param Repository          $repository
     */
    public function __construct(
        PvrEzCommentManager $commentManager,
        $connection,
        LocaleConverter $locale,
        TranslatorInterface $translator,
        Repository $repository
    ) {
        $this->commentManager = $commentManager;
        $this->connection = $connection;
        $this->locale = $locale;
        $this->translator = $translator;
        $this->repository = $repository;
    }

    /**
     * SecurityContext Dependency Injection.
     *
     * @param AuthorizationCheckerInterface $security
     */
    public function setSecurity(AuthorizationCheckerInterface $security)
    {
        $this->security = $security;
    }

    /**
     * Fetch contents from a content Id.
     *
     * @param Request $request
     * @param int     $contentId
     *
     * @return array
     */
    public function getComments(Request $request, int $contentId)
    {
        $viewParameters = $request->attributes->get('viewParameters');
        $comments = $this->commentManager->getComments($contentId, $viewParameters);

        return
            array(
                'comments' => $comments,
                'contentId' => $contentId,
                'reply' => $this->commentManager->canReply(),
            );
    }

    /**
     * @param int     $contentId
     * @param Request $request
     *
     * @return Response return a json message
     */
    public function addComments(int $contentId, Request $request)
    {
        // TODO : This method 'Comment::addComments' must be refactored
        // Check if user is anonymous or not and generate correct form
        $isAnonymous = true;

        if ($this->commentManager->hasAnonymousAccess()) {
            $form = $this->commentManager->createAnonymousForm();
        }
        if ($this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            $isAnonymous = false;
            $form = $this->commentManager->createUserForm();
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = null;
            // Save data depending of user (anonymous or ezuser)
            if ($isAnonymous) {
                $commentId = $this->commentManager->addAnonymousComment(
                    $request,
                    $this->locale,
                    $form->getData(),
                    $contentId,
                    $request->getSession()->getId()
                );
            } else {
                $currentUser = $this->repository->getCurrentUser();

                $commentId = $this->commentManager->addComment(
                    $request,
                    $currentUser,
                    $this->locale,
                    $form->getData(),
                    $contentId,
                    $request->getSession()->getId()
                );
            }

            // Check if you need to moderate comment or not
            if ($this->commentManager->hasModeration()) {
                $this->commentManager->sendMessage(
                    $form->getData(),
                    $currentUser,
                    $contentId,
                    $request->getSession()->getId(),
                    $commentId
                );

                return new Response(
                    $this->translator->trans('Your comment should be moderate before publishing')
                );
            }

            return new Response(
                $this->translator->trans('Your comment has been added correctly')
            );
        }

        $errors = $this->commentManager->getErrorMessages($form);

        $response = new Response(json_encode($errors), 406);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @return mixed|null
     */
    public function generateForm()
    {
        $form = null;

        // Case: configuration set to anonymous
        if ($this->commentManager->hasAnonymousAccess()) {
            $form = $this->commentManager->createAnonymousForm();
        }

        if ($this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            $form = $this->commentManager->createUserForm();
        }

        return $form;
    }
}
