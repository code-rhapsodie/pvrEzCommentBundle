<?php

/*
 * This file is part of the pvrEzComment package.
 *
 * (c) Philippe Vincent-Royol <vincent.royol@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace pvr\EzCommentBundle\Comment;

use eZ\Publish\Core\MVC\Symfony\Locale\LocaleConverter;
use eZ\Publish\Core\Repository\Values\User\User as EzUser;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Request;

interface PvrEzCommentManagerInterface
{
    /**
     * @param int $contentId Get content Id to fetch comments
     * @param array $viewParameters
     * @param int $status
     * @return mixed Array or false
     */
    public function getComments(int $contentId, array $viewParameters = array(), int $status = self::COMMENT_ACCEPT);

    /**
     * @param Request $request
     * @param EzUser $currentUser
     * @param LocaleConverter $localeService
     * @param array $data
     * @param null $contentId
     * @throws \InvalidArgumentException
     */
    public function addComment(
        Request $request,
        EzUser $currentUser,
        LocaleConverter $localeService,
        $data = array(),
        $contentId = null
    );

    public function addAnonymousComment(
        Request $request,
        LocaleConverter $localeService,
        array $data,
        $contentId
    );

    public function createAnonymousForm();

    public function createUserForm();

    /**
     * Get validation error from form
     *
     * @param \Symfony\Component\Form\Form $form the form
     * @return array errors messages
     */
    public function getErrorMessages(Form $form);

    /**
     * Send message to admin(s)
     */
    public function sendMessage($data, $user, $contentId, $sessionId, $commentId);

    public function canUpdate($contentId, $sessionHash, $commentId);

    public function updateStatus($commentId, $status = self::COMMENT_ACCEPT);

    public function getCountComments($contentId);

    /**
     * @return bool
     */
    public function hasAnonymousAccess();

    /**
     * @return bool
     */
    public function hasModeration();
}

