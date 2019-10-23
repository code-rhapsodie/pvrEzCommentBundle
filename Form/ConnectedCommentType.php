<?php

declare(strict_types=1);

namespace pvr\EzCommentBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class ConnectedCommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
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
            ]);
    }
}
