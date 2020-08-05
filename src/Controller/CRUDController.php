<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Controller;

use Doctrine\Inflector\InflectorFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Admin\BreadcrumbsBuilder;
use Sonata\AdminBundle\Admin\FieldDescriptionCollection;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\AdminBundle\Bridge\Exporter\AdminExporter;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Exception\LockException;
use Sonata\AdminBundle\Exception\ModelManagerException;
use Sonata\AdminBundle\Model\AuditManagerInterface;
use Sonata\AdminBundle\Templating\TemplateRegistryInterface;
use Sonata\AdminBundle\Util\AdminObjectAclData;
use Sonata\AdminBundle\Util\AdminObjectAclManipulator;
use Sonata\AdminBundle\Util\AdminObjectAclUserManager;
use Sonata\Exporter\Exporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class CRUDController extends AbstractController
{
    /**
     * The related Admin class.
     *
     * @var AdminInterface
     */
    protected $admin;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var Pool
     */
    private $pool;

    /**
     * The template registry of the related Admin class.
     *
     * @var TemplateRegistryInterface
     */
    private $templateRegistry;

    /**
     * @var BreadcrumbsBuilder
     */
    private $breadcrumbsBuilder;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var bool
     */
    private $kernelDebug;

    /**
     * @var AuditManagerInterface
     */
    private $auditManager;

    /**
     * @var Exporter|null
     */
    private $exporter;

    /**
     * @var AdminExporter|null
     */
    private $adminExporter;

    /**
     * @var CsrfTokenManagerInterface|null
     */
    private $csrfTokenManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var AdminObjectAclManipulator|null
     */
    private $adminObjectAclManipulator;

    /**
     * @var AdminObjectAclUserManager|null
     */
    private $adminObjectAclUserManager;

    public function __construct(
        RequestStack $requestStack,
        Pool $pool,
        TemplateRegistryInterface $templateRegistry,
        BreadcrumbsBuilder $breadcrumbsBuilder,
        Environment $twig,
        TranslatorInterface $translator,
        SessionInterface $session,
        bool $kernelDebug,
        AuditManagerInterface $auditManager,
        ?Exporter $exporter = null,
        ?AdminExporter $adminExporter = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?LoggerInterface $logger = null,
        ?AdminObjectAclManipulator $adminObjectAclManipulator = null,
        ?AdminObjectAclUserManager $adminObjectAclUserManager = null
    ) {
        $this->requestStack = $requestStack;
        $this->pool = $pool;
        $this->templateRegistry = $templateRegistry;
        $this->breadcrumbsBuilder = $breadcrumbsBuilder;
        $this->twig = $twig;
        $this->translator = $translator;
        $this->session = $session;
        $this->kernelDebug = $kernelDebug;
        $this->auditManager = $auditManager;
        $this->exporter = $exporter;
        $this->adminExporter = $adminExporter;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->logger = $logger ?? new NullLogger();
        $this->adminObjectAclManipulator = $adminObjectAclManipulator;
        $this->adminObjectAclUserManager = $adminObjectAclUserManager;

        $this->configure();
    }

    /**
     * Renders a view while passing mandatory parameters on to the template.
     *
     * @param string               $view       The view name
     * @param array<string, mixed> $parameters An array of parameters to pass to the view
     */
    public function renderWithExtraParams($view, array $parameters = [], ?Response $response = null): Response
    {
        $response = $response ?? new Response();

        return $response->setContent($this->twig->render($view, $this->addRenderExtraParams($parameters)));
    }

    /**
     * List action.
     *
     * @throws AccessDeniedException If access is not granted
     *
     * @return Response
     */
    public function listAction(Request $request)
    {
        $this->admin->checkAccess('list');

        $preResponse = $this->preList($request);
        if (null !== $preResponse) {
            return $preResponse;
        }

        if ($listMode = $request->get('_list_mode')) {
            $this->admin->setListMode($listMode);
        }

        $datagrid = $this->admin->getDatagrid();
        $formView = $datagrid->getForm()->createView();

        // set the theme for the current Admin Form
        $this->setFormTheme($formView, $this->admin->getFilterTheme());

        $template = $this->templateRegistry->getTemplate('list');

        return $this->renderWithExtraParams($template, [
            'action' => 'list',
            'form' => $formView,
            'datagrid' => $datagrid,
            'csrf_token' => $this->getCsrfToken('sonata.batch'),
            'export_formats' => $this->adminExporter ?
                $this->adminExporter->getAvailableFormats($this->admin) :
                $this->admin->getExportFormats(),
        ]);
    }

    /**
     * Execute a batch delete.
     *
     * @throws AccessDeniedException If access is not granted
     *
     * @return RedirectResponse
     */
    public function batchActionDelete(ProxyQueryInterface $query)
    {
        $this->admin->checkAccess('batchDelete');

        $modelManager = $this->admin->getModelManager();

        try {
            $modelManager->batchDelete($this->admin->getClass(), $query);
            $this->addFlash(
                'sonata_flash_success',
                $this->trans('flash_batch_delete_success', [], 'SonataAdminBundle')
            );
        } catch (ModelManagerException $e) {
            $this->handleModelManagerException($e);
            $this->addFlash(
                'sonata_flash_error',
                $this->trans('flash_batch_delete_error', [], 'SonataAdminBundle')
            );
        }

        return $this->redirectToList();
    }

    /**
     * Delete action.
     *
     * @throws NotFoundHttpException If the object does not exist
     * @throws AccessDeniedException If access is not granted
     *
     * @return Response|RedirectResponse
     */
    public function deleteAction(Request $request)
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('delete', $object);

        $preResponse = $this->preDelete($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        if (Request::METHOD_DELETE === $this->getRestMethod()) {
            // check the csrf token
            $this->validateCsrfToken('sonata.delete');

            $objectName = $this->admin->toString($object);

            try {
                $this->admin->delete($object);

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(['result' => 'ok'], Response::HTTP_OK, []);
                }

                $this->addFlash(
                    'sonata_flash_success',
                    $this->trans(
                        'flash_delete_success',
                        ['%name%' => $this->escapeHtml($objectName)],
                        'SonataAdminBundle'
                    )
                );
            } catch (ModelManagerException $e) {
                $this->handleModelManagerException($e);

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(['result' => 'error'], Response::HTTP_OK, []);
                }

                $this->addFlash(
                    'sonata_flash_error',
                    $this->trans(
                        'flash_delete_error',
                        ['%name%' => $this->escapeHtml($objectName)],
                        'SonataAdminBundle'
                    )
                );
            }

            return $this->redirectTo($object);
        }

        $template = $this->templateRegistry->getTemplate('delete');

        return $this->renderWithExtraParams($template, [
            'object' => $object,
            'action' => 'delete',
            'csrf_token' => $this->getCsrfToken('sonata.delete'),
        ]);
    }

    /**
     * Edit action.
     *
     * @throws NotFoundHttpException If the object does not exist
     * @throws AccessDeniedException If access is not granted
     *
     * @return Response|RedirectResponse
     */
    public function editAction(Request $request)
    {
        // the key used to lookup the template
        $templateKey = 'edit';

        $id = $request->get($this->admin->getIdParameter());
        $existingObject = $this->admin->getObject($id);

        if (!$existingObject) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->checkParentChildAssociation($request, $existingObject);

        $this->admin->checkAccess('edit', $existingObject);

        $preResponse = $this->preEdit($request, $existingObject);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($existingObject);
        $objectId = $this->admin->getNormalizedIdentifier($existingObject);

        $form = $this->admin->getForm();

        $form->setData($existingObject);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $isFormValid = $form->isValid();

            // persist if the form was valid and if in preview mode the preview was approved
            if ($isFormValid && (!$this->isInPreviewMode() || $this->isPreviewApproved())) {
                $submittedObject = $form->getData();
                $this->admin->setSubject($submittedObject);

                try {
                    $existingObject = $this->admin->update($submittedObject);

                    if ($this->isXmlHttpRequest()) {
                        return $this->handleXmlHttpRequestSuccessResponse($request, $existingObject);
                    }

                    $this->addFlash(
                        'sonata_flash_success',
                        $this->trans(
                            'flash_edit_success',
                            ['%name%' => $this->escapeHtml($this->admin->toString($existingObject))],
                            'SonataAdminBundle'
                        )
                    );

                    // redirect to edit mode
                    return $this->redirectTo($existingObject);
                } catch (ModelManagerException $e) {
                    $this->handleModelManagerException($e);

                    $isFormValid = false;
                } catch (LockException $e) {
                    $this->addFlash('sonata_flash_error', $this->trans('flash_lock_error', [
                        '%name%' => $this->escapeHtml($this->admin->toString($existingObject)),
                        '%link_start%' => sprintf('<a href="%s">', $this->admin->generateObjectUrl('edit', $existingObject)),
                        '%link_end%' => '</a>',
                    ], 'SonataAdminBundle'));
                }
            }

            // show an error message if the form failed validation
            if (!$isFormValid) {
                if ($this->isXmlHttpRequest() && null !== ($response = $this->handleXmlHttpRequestErrorResponse($request, $form))) {
                    return $response;
                }

                $this->addFlash(
                    'sonata_flash_error',
                    $this->trans(
                        'flash_edit_error',
                        ['%name%' => $this->escapeHtml($this->admin->toString($existingObject))],
                        'SonataAdminBundle'
                    )
                );
            } elseif ($this->isPreviewRequested()) {
                // enable the preview template if the form was valid and preview was requested
                $templateKey = 'preview';
                $this->admin->getShow();
            }
        }

        $formView = $form->createView();
        // set the theme for the current Admin Form
        $this->setFormTheme($formView, $this->admin->getFormTheme());

        $template = $this->templateRegistry->getTemplate($templateKey);

        return $this->renderWithExtraParams($template, [
            'action' => 'edit',
            'form' => $formView,
            'object' => $existingObject,
            'objectId' => $objectId,
        ]);
    }

    /**
     * Batch action.
     *
     * @throws NotFoundHttpException If the HTTP method is not POST
     * @throws \RuntimeException     If the batch action is not defined
     *
     * @return Response|RedirectResponse
     */
    public function batchAction(Request $request)
    {
        $restMethod = $this->getRestMethod();

        if (Request::METHOD_POST !== $restMethod) {
            throw $this->createNotFoundException(sprintf(
                'Invalid request method given "%s", %s expected',
                $restMethod,
                Request::METHOD_POST
            ));
        }

        // check the csrf token
        $this->validateCsrfToken('sonata.batch');

        $confirmation = $request->get('confirmation', false);

        if ($data = json_decode($request->get('data', ''), true)) {
            $action = $data['action'];
            $idx = $data['idx'];
            $allElements = $data['all_elements'];
            $request->request->replace(array_merge($request->request->all(), $data));
        } else {
            $request->request->set('idx', $request->get('idx', []));
            $request->request->set('all_elements', $request->get('all_elements', false));

            $action = $request->get('action');
            $idx = $request->get('idx');
            $allElements = $request->get('all_elements');
            $data = $request->request->all();

            unset($data['_sonata_csrf_token']);
        }

        $batchActions = $this->admin->getBatchActions();
        if (!\array_key_exists($action, $batchActions)) {
            throw new \RuntimeException(sprintf('The `%s` batch action is not defined', $action));
        }

        $camelizedAction = InflectorFactory::create()->build()->classify($action);
        $isRelevantAction = sprintf('batchAction%sIsRelevant', $camelizedAction);

        if (method_exists($this, $isRelevantAction)) {
            $nonRelevantMessage = $this->$isRelevantAction($idx, $allElements, $request);
        } else {
            $nonRelevantMessage = 0 !== \count($idx) || $allElements; // at least one item is selected
        }

        if (!$nonRelevantMessage) { // default non relevant message (if false of null)
            $nonRelevantMessage = 'flash_batch_empty';
        }

        $datagrid = $this->admin->getDatagrid();
        $datagrid->buildPager();

        if (true !== $nonRelevantMessage) {
            $this->addFlash(
                'sonata_flash_info',
                $this->trans($nonRelevantMessage, [], 'SonataAdminBundle')
            );

            return $this->redirectToList();
        }

        $askConfirmation = $batchActions[$action]['ask_confirmation'] ??
            true;

        if ($askConfirmation && 'ok' !== $confirmation) {
            $actionLabel = $batchActions[$action]['label'];
            $batchTranslationDomain = $batchActions[$action]['translation_domain'] ??
                $this->admin->getTranslationDomain();

            $formView = $datagrid->getForm()->createView();
            $this->setFormTheme($formView, $this->admin->getFilterTheme());

            $template = !empty($batchActions[$action]['template']) ?
                $batchActions[$action]['template'] :
                $this->templateRegistry->getTemplate('batch_confirmation');

            return $this->renderWithExtraParams($template, [
                'action' => 'list',
                'action_label' => $actionLabel,
                'batch_translation_domain' => $batchTranslationDomain,
                'datagrid' => $datagrid,
                'form' => $formView,
                'data' => $data,
                'csrf_token' => $this->getCsrfToken('sonata.batch'),
            ]);
        }

        // execute the action, batchActionXxxxx
        $finalAction = sprintf('batchAction%s', $camelizedAction);
        if (!method_exists($this, $finalAction)) {
            throw new \RuntimeException(sprintf('A `%s::%s` method must be callable', static::class, $finalAction));
        }

        $query = $datagrid->getQuery();

        $query->setFirstResult(null);
        $query->setMaxResults(null);

        $this->admin->preBatchAction($action, $query, $idx, $allElements);

        if (\count($idx) > 0) {
            $this->admin->getModelManager()->addIdentifiersToQuery($this->admin->getClass(), $query, $idx);
        } elseif (!$allElements) {
            $this->addFlash(
                'sonata_flash_info',
                $this->trans('flash_batch_no_elements_processed', [], 'SonataAdminBundle')
            );

            return $this->redirectToList();
        }

        return $this->$finalAction($query, $request);
    }

    /**
     * Create action.
     *
     * @throws AccessDeniedException If access is not granted
     *
     * @return Response
     */
    public function createAction(Request $request)
    {
        // the key used to lookup the template
        $templateKey = 'edit';

        $this->admin->checkAccess('create');

        $class = new \ReflectionClass($this->admin->hasActiveSubClass() ? $this->admin->getActiveSubClass() : $this->admin->getClass());

        if ($class->isAbstract()) {
            return $this->renderWithExtraParams(
                '@SonataAdmin/CRUD/select_subclass.html.twig',
                [
                    'base_template' => $this->getBaseTemplate(),
                    'admin' => $this->admin,
                    'action' => 'create',
                ]
            );
        }

        $newObject = $this->admin->getNewInstance();

        $preResponse = $this->preCreate($request, $newObject);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($newObject);

        $form = $this->admin->getForm();

        $form->setData($newObject);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $isFormValid = $form->isValid();

            // persist if the form was valid and if in preview mode the preview was approved
            if ($isFormValid && (!$this->isInPreviewMode() || $this->isPreviewApproved())) {
                $submittedObject = $form->getData();
                $this->admin->setSubject($submittedObject);
                $this->admin->checkAccess('create', $submittedObject);

                try {
                    $newObject = $this->admin->create($submittedObject);

                    if ($this->isXmlHttpRequest()) {
                        return $this->handleXmlHttpRequestSuccessResponse($request, $newObject);
                    }

                    $this->addFlash(
                        'sonata_flash_success',
                        $this->trans(
                            'flash_create_success',
                            ['%name%' => $this->escapeHtml($this->admin->toString($newObject))],
                            'SonataAdminBundle'
                        )
                    );

                    // redirect to edit mode
                    return $this->redirectTo($newObject);
                } catch (ModelManagerException $e) {
                    $this->handleModelManagerException($e);

                    $isFormValid = false;
                }
            }

            // show an error message if the form failed validation
            if (!$isFormValid) {
                if ($this->isXmlHttpRequest() && null !== ($response = $this->handleXmlHttpRequestErrorResponse($request, $form))) {
                    return $response;
                }

                $this->addFlash(
                    'sonata_flash_error',
                    $this->trans(
                        'flash_create_error',
                        ['%name%' => $this->escapeHtml($this->admin->toString($newObject))],
                        'SonataAdminBundle'
                    )
                );
            } elseif ($this->isPreviewRequested()) {
                // pick the preview template if the form was valid and preview was requested
                $templateKey = 'preview';
                $this->admin->getShow();
            }
        }

        $formView = $form->createView();
        // set the theme for the current Admin Form
        $this->setFormTheme($formView, $this->admin->getFormTheme());

        $template = $this->templateRegistry->getTemplate($templateKey);

        return $this->renderWithExtraParams($template, [
            'action' => 'create',
            'form' => $formView,
            'object' => $newObject,
            'objectId' => null,
        ]);
    }

    /**
     * Show action.
     *
     * @throws NotFoundHttpException If the object does not exist
     * @throws AccessDeniedException If access is not granted
     *
     * @return Response
     */
    public function showAction(Request $request)
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();
        \assert($fields instanceof FieldDescriptionCollection);

        $template = $this->templateRegistry->getTemplate('show');

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $object,
            'elements' => $fields,
        ]);
    }

    /**
     * Show history revisions for object.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object does not exist or the audit reader is not available
     *
     * @return Response
     */
    public function historyAction(Request $request)
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('history', $object);

        if (!$this->auditManager->hasReader($this->admin->getClass())) {
            throw $this->createNotFoundException(
                sprintf(
                    'unable to find the audit reader for class : %s',
                    $this->admin->getClass()
                )
            );
        }

        $reader = $this->auditManager->getReader($this->admin->getClass());

        $revisions = $reader->findRevisions($this->admin->getClass(), $id);

        $template = $this->templateRegistry->getTemplate('history');

        return $this->renderWithExtraParams($template, [
            'action' => 'history',
            'object' => $object,
            'revisions' => $revisions,
            'currentRevision' => $revisions ? current($revisions) : false,
        ]);
    }

    /**
     * View history revision of object.
     *
     * @param string|null $revision
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object or revision does not exist or the audit reader is not available
     *
     * @return Response
     */
    public function historyViewRevisionAction(Request $request, $revision = null)
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('historyViewRevision', $object);

        if (!$this->auditManager->hasReader($this->admin->getClass())) {
            throw $this->createNotFoundException(
                sprintf(
                    'unable to find the audit reader for class : %s',
                    $this->admin->getClass()
                )
            );
        }

        $reader = $this->auditManager->getReader($this->admin->getClass());

        // retrieve the revisioned object
        $object = $reader->find($this->admin->getClass(), $id, $revision);

        if (!$object) {
            throw $this->createNotFoundException(sprintf(
                'unable to find the targeted object `%s` from the revision `%s` with classname : `%s`',
                $id,
                $revision,
                $this->admin->getClass()
            ));
        }

        $this->admin->setSubject($object);

        $template = $this->templateRegistry->getTemplate('show');

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $object,
            'elements' => $this->admin->getShow(),
        ]);
    }

    /**
     * Compare history revisions of object.
     *
     * @param int|string|null $baseRevision
     * @param int|string|null $compareRevision
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object or revision does not exist or the audit reader is not available
     *
     * @return Response
     */
    public function historyCompareRevisionsAction(Request $request, $baseRevision = null, $compareRevision = null)
    {
        $this->admin->checkAccess('historyCompareRevisions');

        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        if (!$this->auditManager->hasReader($this->admin->getClass())) {
            throw $this->createNotFoundException(
                sprintf(
                    'unable to find the audit reader for class : %s',
                    $this->admin->getClass()
                )
            );
        }

        $reader = $this->auditManager->getReader($this->admin->getClass());

        // retrieve the base revision
        $baseObject = $reader->find($this->admin->getClass(), $id, $baseRevision);
        if (!$baseObject) {
            throw $this->createNotFoundException(
                sprintf(
                    'unable to find the targeted object `%s` from the revision `%s` with classname : `%s`',
                    $id,
                    $baseRevision,
                    $this->admin->getClass()
                )
            );
        }

        // retrieve the compare revision
        $compareObject = $reader->find($this->admin->getClass(), $id, $compareRevision);
        if (!$compareObject) {
            throw $this->createNotFoundException(
                sprintf(
                    'unable to find the targeted object `%s` from the revision `%s` with classname : `%s`',
                    $id,
                    $compareRevision,
                    $this->admin->getClass()
                )
            );
        }

        $this->admin->setSubject($baseObject);

        $template = $this->templateRegistry->getTemplate('show_compare');

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $baseObject,
            'object_compare' => $compareObject,
            'elements' => $this->admin->getShow(),
        ]);
    }

    /**
     * Export data to specified format.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws \RuntimeException     If the export format is invalid
     *
     * @return Response
     */
    public function exportAction(Request $request)
    {
        $this->admin->checkAccess('export');

        $format = $request->get('format');

        $allowedExportFormats = $this->adminExporter->getAvailableFormats($this->admin);
        $filename = $this->adminExporter->getExportFilename($this->admin, $format);

        if (!\in_array($format, $allowedExportFormats, true)) {
            throw new \RuntimeException(sprintf(
                'Export in format `%s` is not allowed for class: `%s`. Allowed formats are: `%s`',
                $format,
                $this->admin->getClass(),
                implode(', ', $allowedExportFormats)
            ));
        }

        return $this->exporter->getResponse(
            $format,
            $filename,
            $this->admin->getDataSourceIterator()
        );
    }

    /**
     * Returns the Response object associated to the acl action.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object does not exist or the ACL is not enabled
     *
     * @return Response|RedirectResponse
     */
    public function aclAction(Request $request)
    {
        if (!$this->admin->isAclEnabled()) {
            throw $this->createNotFoundException('ACL are not enabled for this admin');
        }

        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('acl', $object);

        $this->admin->setSubject($object);
        $aclUsers = $this->getAclUsers();
        $aclRoles = $this->getAclRoles();

        $adminObjectAclData = new AdminObjectAclData(
            $this->admin,
            $object,
            $aclUsers,
            $this->adminObjectAclManipulator->getMaskBuilderClass(),
            $aclRoles
        );

        $aclUsersForm = $this->adminObjectAclManipulator->createAclUsersForm($adminObjectAclData);
        $aclRolesForm = $this->adminObjectAclManipulator->createAclRolesForm($adminObjectAclData);

        if (Request::METHOD_POST === $request->getMethod()) {
            if ($request->request->has(AdminObjectAclManipulator::ACL_USERS_FORM_NAME)) {
                $form = $aclUsersForm;
                $updateMethod = 'updateAclUsers';
            } elseif ($request->request->has(AdminObjectAclManipulator::ACL_ROLES_FORM_NAME)) {
                $form = $aclRolesForm;
                $updateMethod = 'updateAclRoles';
            }

            if (isset($form, $updateMethod)) {
                $form->handleRequest($request);

                if ($form->isValid()) {
                    $this->adminObjectAclManipulator->$updateMethod($adminObjectAclData);
                    $this->addFlash(
                        'sonata_flash_success',
                        $this->trans('flash_acl_edit_success', [], 'SonataAdminBundle')
                    );

                    return new RedirectResponse($this->admin->generateObjectUrl('acl', $object));
                }
            }
        }

        $template = $this->templateRegistry->getTemplate('acl');

        return $this->renderWithExtraParams($template, [
            'action' => 'acl',
            'permissions' => $adminObjectAclData->getUserPermissions(),
            'object' => $object,
            'users' => $aclUsers,
            'roles' => $aclRoles,
            'aclUsersForm' => $aclUsersForm->createView(),
            'aclRolesForm' => $aclRolesForm->createView(),
        ]);
    }

    public function getRequest(): ?Request
    {
        return $this->requestStack->getCurrentRequest();
    }

    protected function addFlash(string $type, $message): void
    {
        if ($this->session instanceof Session) {
            $this->session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    protected function addRenderExtraParams(array $parameters = []): array
    {
        if (!$this->isXmlHttpRequest()) {
            $parameters['breadcrumbs_builder'] = $this->breadcrumbsBuilder;
        }

        $parameters['admin'] = $parameters['admin'] ?? $this->admin;
        $parameters['base_template'] = $parameters['base_template'] ?? $this->getBaseTemplate();
        $parameters['admin_pool'] = $this->pool;

        return $parameters;
    }

    /**
     * Render JSON.
     *
     * @param mixed $data
     * @param int   $status
     * @param array $headers
     *
     * @return JsonResponse with json encoded data
     */
    protected function renderJson($data, $status = Response::HTTP_OK, $headers = [])
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Returns true if the request is a XMLHttpRequest.
     *
     * @return bool True if the request is an XMLHttpRequest, false otherwise
     */
    protected function isXmlHttpRequest()
    {
        $request = $this->getRequest();

        return $request->isXmlHttpRequest() || $request->get('_xml_http_request');
    }

    /**
     * Returns the correct RESTful verb, given either by the request itself or
     * via the "_method" parameter.
     *
     * @return string HTTP method, either
     */
    protected function getRestMethod()
    {
        $request = $this->getRequest();

        if (Request::getHttpMethodParameterOverride() || !$request->request->has('_method')) {
            return $request->getMethod();
        }

        return $request->request->get('_method');
    }

    /**
     * Contextualize the admin class depends on the current request.
     *
     * @throws \RuntimeException
     */
    protected function configure(): void
    {
        $request = $this->getRequest();

        $adminCode = $request->get('_sonata_admin');

        if (!$adminCode) {
            throw new \RuntimeException(sprintf(
                'There is no `_sonata_admin` defined for the controller `%s` and the current route `%s`',
                static::class,
                $request->get('_route')
            ));
        }

        try {
            $this->admin = $this->pool->getAdminByAdminCode($adminCode);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(sprintf(
                'Unable to find the admin class related to the current controller (%s)',
                static::class
            ));
        }

        $rootAdmin = $this->admin;

        while ($rootAdmin->isChild()) {
            $rootAdmin->setCurrentChild(true);
            $rootAdmin = $rootAdmin->getParent();
        }

        $rootAdmin->setRequest($request);

        if ($request->get('uniqid')) {
            $this->admin->setUniqid($request->get('uniqid'));
        }
    }

    /**
     * Proxy for the logger service of the container.
     * If no such service is found, a NullLogger is returned.
     */
    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Returns the base template name.
     *
     * @return string The template name
     */
    protected function getBaseTemplate()
    {
        if ($this->isXmlHttpRequest()) {
            return $this->templateRegistry->getTemplate('ajax');
        }

        return $this->templateRegistry->getTemplate('layout');
    }

    /**
     * @throws \Exception
     */
    protected function handleModelManagerException(\Exception $e): void
    {
        if ($this->kernelDebug) {
            throw $e;
        }

        $context = ['exception' => $e];
        if ($e->getPrevious()) {
            $context['previous_exception_message'] = $e->getPrevious()->getMessage();
        }
        $this->getLogger()->error($e->getMessage(), $context);
    }

    /**
     * Redirect the user depend on this choice.
     *
     * @param object $object
     *
     * @return RedirectResponse
     */
    protected function redirectTo($object)
    {
        $request = $this->getRequest();

        $url = false;

        if (null !== $request->get('btn_update_and_list')) {
            return $this->redirectToList();
        }
        if (null !== $request->get('btn_create_and_list')) {
            return $this->redirectToList();
        }

        if (null !== $request->get('btn_create_and_create')) {
            $params = [];
            if ($this->admin->hasActiveSubClass()) {
                $params['subclass'] = $request->get('subclass');
            }
            $url = $this->admin->generateUrl('create', $params);
        }

        if ('DELETE' === $this->getRestMethod()) {
            return $this->redirectToList();
        }

        if (!$url) {
            foreach (['edit', 'show'] as $route) {
                if ($this->admin->hasRoute($route) && $this->admin->hasAccess($route, $object)) {
                    $url = $this->admin->generateObjectUrl(
                        $route,
                        $object,
                        $this->getSelectedTab($request)
                    );

                    break;
                }
            }
        }

        if (!$url) {
            return $this->redirectToList();
        }

        return new RedirectResponse($url);
    }

    /**
     * Redirects the user to the list view.
     *
     * @return RedirectResponse
     */
    final protected function redirectToList()
    {
        $parameters = [];

        if ($filter = $this->admin->getFilterParameters()) {
            $parameters['filter'] = $filter;
        }

        return $this->redirect($this->admin->generateUrl('list', $parameters));
    }

    /**
     * Returns true if the preview is requested to be shown.
     *
     * @return bool
     */
    protected function isPreviewRequested()
    {
        $request = $this->getRequest();

        return null !== $request->get('btn_preview');
    }

    /**
     * Returns true if the preview has been approved.
     *
     * @return bool
     */
    protected function isPreviewApproved()
    {
        $request = $this->getRequest();

        return null !== $request->get('btn_preview_approve');
    }

    /**
     * Returns true if the request is in the preview workflow.
     *
     * That means either a preview is requested or the preview has already been shown
     * and it got approved/declined.
     *
     * @return bool
     */
    protected function isInPreviewMode()
    {
        return $this->admin->supportsPreviewMode()
        && ($this->isPreviewRequested()
            || $this->isPreviewApproved()
            || $this->isPreviewDeclined());
    }

    /**
     * Returns true if the preview has been declined.
     *
     * @return bool
     */
    protected function isPreviewDeclined()
    {
        $request = $this->getRequest();

        return null !== $request->get('btn_preview_decline');
    }

    /**
     * Gets ACL users.
     */
    protected function getAclUsers(): \Traversable
    {
        $aclUsers = $this->adminObjectAclUserManager instanceof AdminObjectAclUserManager ?
            $this->adminObjectAclUserManager->findUsers() :
            [];

        return \is_array($aclUsers) ? new \ArrayIterator($aclUsers) : $aclUsers;
    }

    /**
     * Gets ACL roles.
     */
    protected function getAclRoles(): \Traversable
    {
        $aclRoles = [];
        $roleHierarchy = $this->pool->getContainer()->getParameter('security.role_hierarchy.roles');

        foreach ($this->pool->getAdminServiceIds() as $id) {
            try {
                $admin = $this->pool->getInstance($id);
            } catch (\Exception $e) {
                continue;
            }

            $baseRole = $admin->getSecurityHandler()->getBaseRole($admin);
            foreach ($admin->getSecurityInformation() as $role => $permissions) {
                $role = sprintf($baseRole, $role);
                $aclRoles[] = $role;
            }
        }

        foreach ($roleHierarchy as $name => $roles) {
            $aclRoles[] = $name;
            $aclRoles = array_merge($aclRoles, $roles);
        }

        $aclRoles = array_unique($aclRoles);

        return \is_array($aclRoles) ? new \ArrayIterator($aclRoles) : $aclRoles;
    }

    /**
     * Validate CSRF token for action without form.
     *
     * @param string $intention
     *
     * @throws HttpException
     */
    protected function validateCsrfToken($intention): void
    {
        $request = $this->getRequest();
        $token = $request->get('_sonata_csrf_token');

        if ($this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
            $valid = $this->csrfTokenManager->isTokenValid(new CsrfToken($intention, $token));
        } else {
            return;
        }

        if (!$valid) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'The csrf token is not valid, CSRF attack?');
        }
    }

    /**
     * Escape string for html output.
     *
     * @param string $s
     *
     * @return string
     */
    protected function escapeHtml($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Get CSRF token.
     *
     * @param string $intention
     *
     * @return string|false
     */
    protected function getCsrfToken($intention)
    {
        if ($this->csrfTokenManager) {
            return $this->csrfTokenManager->getToken($intention)->getValue();
        }

        return false;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from createAction.
     *
     * @param object $object
     *
     * @return Response|null
     */
    protected function preCreate(Request $request, $object)
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from editAction.
     *
     * @param object $object
     *
     * @return Response|null
     */
    protected function preEdit(Request $request, $object)
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from deleteAction.
     *
     * @param object $object
     *
     * @return Response|null
     */
    protected function preDelete(Request $request, $object)
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from showAction.
     *
     * @param object $object
     *
     * @return Response|null
     */
    protected function preShow(Request $request, $object)
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from listAction.
     *
     * @return Response|null
     */
    protected function preList(Request $request)
    {
        return null;
    }

    /**
     * Translate a message id.
     *
     * @param string $id
     * @param string $domain
     * @param string $locale
     *
     * @return string translated string
     */
    final protected function trans($id, array $parameters = [], $domain = null, $locale = null)
    {
        $domain = $domain ?: $this->admin->getTranslationDomain();

        return $this->translator->trans($id, $parameters, $domain, $locale);
    }

    private function getSelectedTab(Request $request): array
    {
        return array_filter(['_tab' => $request->request->get('_tab')]);
    }

    private function checkParentChildAssociation(Request $request, object $object): void
    {
        if (!$this->admin->isChild()) {
            return;
        }

        $parentAdmin = $this->admin->getParent();
        $parentId = $request->get($parentAdmin->getIdParameter());

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $propertyPath = new PropertyPath($this->admin->getParentAssociationMapping());

        if ($parentAdmin->getObject($parentId) !== $propertyAccessor->getValue($object, $propertyPath)) {
            throw new \RuntimeException(sprintf(
                'There is no association between "%s" and "%s"',
                $parentAdmin->toString($parentAdmin->getObject($parentId)),
                $this->admin->toString($object)
            ));
        }
    }

    /**
     * Sets the admin form theme to form view. Used for compatibility between Symfony versions.
     */
    private function setFormTheme(FormView $formView, ?array $theme = null): void
    {
        $this->twig->getRuntime(FormRenderer::class)->setTheme($formView, $theme);
    }

    private function handleXmlHttpRequestErrorResponse(Request $request, FormInterface $form): JsonResponse
    {
        if (empty(array_intersect(['application/json', '*/*'], $request->getAcceptableContentTypes()))) {
            return $this->renderJson([], Response::HTTP_NOT_ACCEPTABLE);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $this->renderJson([
            'result' => 'error',
            'errors' => $errors,
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param object $object
     */
    private function handleXmlHttpRequestSuccessResponse(Request $request, $object): JsonResponse
    {
        if (empty(array_intersect(['application/json', '*/*'], $request->getAcceptableContentTypes()))) {
            return $this->renderJson([], Response::HTTP_NOT_ACCEPTABLE);
        }

        return $this->renderJson([
            'result' => 'ok',
            'objectId' => $this->admin->getNormalizedIdentifier($object),
            'objectName' => $this->escapeHtml($this->admin->toString($object)),
        ], Response::HTTP_OK);
    }
}
