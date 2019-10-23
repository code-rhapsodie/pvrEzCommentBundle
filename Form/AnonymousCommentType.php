<?php

declare(strict_types=1);

namespace pvr\EzCommentBundle\Form;

use Gregwar\CaptchaBundle\Type\CaptchaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class AnonymousCommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('email', EmailType::class, [
                'constraints' => [
                    new Email(),
                ],
            ])
            ->add('message', TextareaType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('parent_comment_id', HiddenType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
                'data' => 0,
            ])
            ->add('captcha', CaptchaType::class,
                ['as_url' => true, 'reload' => true]
            );
    }
}
