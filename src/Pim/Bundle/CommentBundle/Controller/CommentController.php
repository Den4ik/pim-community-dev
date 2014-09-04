<?php

namespace Pim\Bundle\CommentBundle\Controller;

use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\ValidatorInterface;
use Symfony\Component\Translation\TranslatorInterface;

use Doctrine\Common\Persistence\ManagerRegistry;

use Pim\Bundle\CommentBundle\Builder\CommentBuilder;
use Pim\Bundle\EnrichBundle\AbstractController\AbstractDoctrineController;

/**
 * @author    Olivier Soulet <olivier.soulet@akeneo.com>
 * @author    Julien Janvier <julien.janvier@akeneo.com>
 * @copyright 2014 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class CommentController extends AbstractDoctrineController
{
    /** @var CommentBuilder */
    protected $commentBuilder;

    /** @var string */
    protected $commentClassName;

    public function __construct(
        Request $request,
        EngineInterface $templating,
        RouterInterface $router,
        SecurityContextInterface $securityContext,
        FormFactoryInterface $formFactory,
        ValidatorInterface $validator,
        TranslatorInterface $translator,
        EventDispatcherInterface $eventDispatcher,
        ManagerRegistry $doctrine,
        CommentBuilder $commentBuilder,
        $commentClassName
    ) {
        parent::__construct(
            $request,
            $templating,
            $router,
            $securityContext,
            $formFactory,
            $validator,
            $translator,
            $eventDispatcher,
            $doctrine
        );

        $this->commentBuilder = $commentBuilder;
        $this->commentClassName = $commentClassName;
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \LogicException
     */
    public function createAction(Request $request)
    {
        if (true !== $request->isXmlHttpRequest()) {
            throw new \LogicException('The request should be an Xml Http request.');
        }

        $comment = $this->commentBuilder->buildCommentWithoutSubject($this->getUser());
        $form = $this->createForm(
            'pim_comment_comment',
            $comment
        );

        $form->submit($this->request);
        if ($form->isValid()) {
            $manager = $this->getManagerForClass(ClassUtils::getClass($comment));
            $manager->persist($comment);
            $manager->flush();
            //TODO: change this
            $this->addFlash('success', 'flash.comment.create.success');
        } else {
            //TODO: change this
            $this->addFlash('error', 'flash.comment.create.error');
        }

        return $this->render(
            'PimCommentBundle:Comment:_thread.html.twig',
            ['comment' => $comment]
        );
    }

    public function replyAction($name)
    {
    }

    /**
     * Delete a comment with his children
     *
     * @param Request $request
     * @param $id
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction(Request $request, $id)
    {
        $manager = $this->getManagerForClass($this->commentClassName);

        $comment = $manager->find($this->commentClassName, $id);

        if (null === $comment) {
            throw new NotFoundHttpException(sprintf('Comment with id %s not found', $id));
        }

        $manager->remove($comment);
        $manager->flush();

        return new JsonResponse('OK');
    }
}
