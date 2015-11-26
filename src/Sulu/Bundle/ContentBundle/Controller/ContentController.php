<?php
/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ContentBundle\Controller;

use FOS\RestBundle\Routing\ClassResourceInterface;
use FOS\RestBundle\View\ViewHandlerInterface;
use Hateoas\Representation\CollectionRepresentation;
use PHPCR\ItemNotFoundException;
use Sulu\Component\Content\Repository\Content;
use Sulu\Component\Content\Repository\ContentRepositoryInterface;
use Sulu\Component\Content\Repository\Mapping\MappingBuilder;
use Sulu\Component\Content\Repository\Mapping\MappingInterface;
use Sulu\Component\PHPCR\SessionManager\SessionManagerInterface;
use Sulu\Component\Rest\Exception\MissingParameterException;
use Sulu\Component\Rest\Exception\ParameterDataTypeException;
use Sulu\Component\Rest\RequestParametersTrait;
use Sulu\Component\Rest\RestController;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Provides api for content querying.
 */
class ContentController extends RestController implements ClassResourceInterface
{
    private static $relationName = 'content';

    const WEBSPACE_NODE_SINGLE = 'single';
    const WEBSPACE_NODES_ALL = 'all';
    use RequestParametersTrait;

    /**
     * @var ContentRepositoryInterface
     */
    private $contentRepository;

    /**
     * @var ViewHandlerInterface
     */
    private $viewHandler;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var WebspaceManagerInterface
     */
    private $webspaceManager;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    public function __construct(
        ContentRepositoryInterface $contentRepository,
        ViewHandlerInterface $viewHandler,
        RouterInterface $router,
        WebspaceManagerInterface $webspaceManager,
        SessionManagerInterface $sessionManager,
        TokenStorageInterface $tokenStorage = null
    ) {
        $this->contentRepository = $contentRepository;
        $this->viewHandler = $viewHandler;
        $this->router = $router;
        $this->webspaceManager = $webspaceManager;
        $this->sessionManager = $sessionManager;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Returns content array by parent or webspace root.
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws MissingParameterException
     * @throws ParameterDataTypeException
     */
    public function cgetAction(Request $request)
    {
        $parent = $request->get('parent');
        $properties = array_filter(explode(',', $request->get('mapping', '')));
        $excludeGhosts = $this->getBooleanRequestParameter($request, 'exclude-ghosts', false, false);
        $excludeShadows = $this->getBooleanRequestParameter($request, 'exclude-shadows', false, false);
        $webspaceNodes = $this->getRequestParameter($request, 'webspace-nodes');
        $locale = $this->getRequestParameter($request, 'locale', true);
        $webspaceKey = $this->getRequestParameter($request, 'webspace', false);

        if (!$webspaceKey && !$webspaceNodes) {
            throw new MissingParameterException(
                get_class($this), sprintf('"%s" or "%s" or "%s"', 'webspace', 'webspace-nodes', 'webspace-nodes')
            );
        }

        if (!in_array($webspaceNodes, [self::WEBSPACE_NODE_SINGLE, self::WEBSPACE_NODES_ALL, null])) {
            throw new ParameterDataTypeException(get_class($this), 'webspace-nodes');
        }

        $user = $this->tokenStorage->getToken()->getUser();

        $mapping = MappingBuilder::create()
            ->disableHydrateGhost(!$excludeGhosts)
            ->disableHydrateShadow(!$excludeShadows)
            ->addProperties($properties)
            ->getMapping();

        $contents = [];
        if ($webspaceKey) {
            if (!$parent) {
                $contents = $this->contentRepository->findByWebspaceRoot($locale, $webspaceKey, $mapping, $user);
            } else {
                $contents = $this->contentRepository->findByParentUuid($parent, $locale, $webspaceKey, $mapping, $user);
            }
        }

        if ($webspaceNodes === self::WEBSPACE_NODES_ALL) {
            $contents = $this->getWebspaceNodes($mapping, $contents, $locale, $user, $webspaceKey);
        } elseif ($webspaceNodes === self::WEBSPACE_NODE_SINGLE) {
            $contents = $this->getWebspaceNode($mapping, $contents, $webspaceKey, $locale, $user);
        }

        $list = new CollectionRepresentation($contents, self::$relationName);
        $view = $this->view($list);

        return $this->viewHandler->handle($view);
    }

    /**
     * Returns single content (tree=false) or all parents with the siblings to the given uuid.
     *
     * @param string $uuid
     * @param Request $request
     *
     * @return Response
     *
     * @throws MissingParameterException
     * @throws ParameterDataTypeException
     */
    public function getAction($uuid, Request $request)
    {
        $properties = array_filter(explode(',', $request->get('mapping', '')));
        $excludeGhosts = $this->getBooleanRequestParameter($request, 'exclude-ghosts', false, false);
        $excludeShadows = $this->getBooleanRequestParameter($request, 'exclude-shadows', false, false);
        $webspaceNodes = $this->getRequestParameter($request, 'webspace-nodes');
        $tree = $this->getBooleanRequestParameter($request, 'tree', false, false);
        $locale = $this->getRequestParameter($request, 'locale', true);
        $webspaceKey = $this->getRequestParameter($request, 'webspace', true);

        $user = $this->tokenStorage->getToken()->getUser();

        $mapping = MappingBuilder::create()
            ->disableHydrateGhost(!$excludeGhosts)
            ->disableHydrateShadow(!$excludeShadows)
            ->addProperties($properties)
            ->getMapping();

        if ($tree) {
            return $this->getTreeAction($uuid, $locale, $webspaceKey, $webspaceNodes, $mapping, $user);
        }

        $data = $this->contentRepository->find($uuid, $locale, $webspaceKey, $mapping, $user);
        $view = $this->view($data);

        return $this->viewHandler->handle($view);
    }

    /**
     * Returns tree response for given uuid.
     *
     * @param string $uuid
     * @param string $locale
     * @param string $webspaceKey
     * @param bool $webspaceNodes
     * @param MappingInterface $mapping
     * @param UserInterface $user
     *
     * @return Response
     *
     * @throws ParameterDataTypeException
     */
    private function getTreeAction(
        $uuid,
        $locale,
        $webspaceKey,
        $webspaceNodes,
        MappingInterface $mapping,
        UserInterface $user
    ) {
        if (!in_array($webspaceNodes, [self::WEBSPACE_NODE_SINGLE, self::WEBSPACE_NODES_ALL], null)) {
            throw new ParameterDataTypeException(get_class($this), 'webspace-nodes');
        }

        try {
            $contents = $this->contentRepository->findParentsWithSiblingsByUuid(
                $uuid,
                $locale,
                $webspaceKey,
                $mapping,
                $user
            );
        } catch (ItemNotFoundException $ex) {
            // TODO return 404 and handle this edge case on client side
            return $this->redirect(
                $this->router->generate(
                    'get_contents',
                    [
                        'locale' => $locale,
                        'webspace' => $webspaceKey,
                        'exclude-ghosts' => !$mapping->hydrateGhost(),
                        'exclude-shadows' => !$mapping->hydrateShadow(),
                        'mapping' => implode(',', $mapping->getProperties()),
                    ]
                )
            );
        }

        if ($webspaceNodes === self::WEBSPACE_NODES_ALL) {
            $contents = $this->getWebspaceNodes($mapping, $contents, $locale, $user, $webspaceKey);
        } elseif ($webspaceNodes === self::WEBSPACE_NODE_SINGLE) {
            $contents = $this->getWebspaceNode($mapping, $contents, $webspaceKey, $locale, $user);
        }

        $view = $this->view(new CollectionRepresentation($contents, self::$relationName));

        return $this->viewHandler->handle($view);
    }

    /**
     * Returns content for all webspaces.
     * If a webspaceKey is given the $contents array will be set as children of this webspace.
     *
     * @param MappingInterface $mapping
     * @param array $contents
     * @param string $locale
     * @param UserInterface $user
     * @param string $webspaceKey
     *
     * @return Content[]
     */
    private function getWebspaceNodes(
        MappingInterface $mapping,
        array $contents,
        $locale,
        UserInterface $user,
        $webspaceKey = null
    ) {
        $paths = [];
        $webspaces = [];
        /** @var Webspace $webspace */
        foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
            $paths[] = $this->sessionManager->getContentPath($webspace->getKey());
            $webspaces[$webspace->getKey()] = $webspace;
        }

        return $this->getWebspaceNodesByPaths($paths, $webspaceKey, $locale, $mapping, $webspaces, $contents, $user);
    }

    /**
     * Returns content for all webspaces.
     * If a webspaceKey is given the $contents array will be set as children of this webspace.
     *
     * @param MappingInterface $mapping
     * @param array $contents
     * @param string $webspaceKey
     * @param string $locale
     * @param UserInterface $user
     *
     * @return Content[]
     */
    private function getWebspaceNode(
        MappingInterface $mapping,
        array $contents,
        $webspaceKey,
        $locale,
        UserInterface $user
    ) {
        $webspace = $this->webspaceManager->findWebspaceByKey($webspaceKey);
        $paths = [$this->sessionManager->getContentPath($webspace->getKey())];
        $webspaces = [$webspace->getKey() => $webspace];

        return $this->getWebspaceNodesByPaths(
            $paths,
            $webspaceKey,
            $locale,
            $mapping,
            $webspaces,
            $contents,
            $user
        );
    }

    /**
     * @param string[] $paths
     * @param string $webspaceKey
     * @param string $locale
     * @param MappingInterface $mapping
     * @param Webspace[] $webspaces
     * @param Content[] $contents
     * @param UserInterface $user
     *
     * @return Content[]
     */
    private function getWebspaceNodesByPaths(
        array $paths,
        $webspaceKey,
        $locale,
        MappingInterface $mapping,
        array $webspaces,
        array $contents,
        UserInterface $user
    ) {
        $webspaceContents = $this->contentRepository->findByPaths($paths, $locale, $mapping, $user);

        foreach ($webspaceContents as $webspaceContent) {
            $webspaceContent->setDataProperty('title', $webspaces[$webspaceContent->getWebspaceKey()]->getName());

            if ($webspaceContent->getWebspaceKey() === $webspaceKey) {
                $webspaceContent->setChildren($contents);
            }
        }

        return $webspaceContents;
    }
}
