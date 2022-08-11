<?php

declare(strict_types=1);

namespace Neos\ContentRepository\NodeMigration;

use Neos\ContentRepository\ContentRepository;
use Neos\ContentRepository\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\SharedModel\Workspace\ContentStreamIdentifier;
use Neos\ContentRepository\NodeMigration\Filter\InvalidMigrationFilterSpecified;
use Neos\ContentRepository\NodeMigration\Command\ExecuteMigration;
use Neos\ContentRepository\NodeMigration\Filter\FiltersFactory;
use Neos\ContentRepository\NodeMigration\Transformation\TransformationsFactory;
use Neos\ContentRepository\Feature\WorkspaceCreation\Command\CreateWorkspace;
use Neos\ContentRepository\Feature\Common\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\SharedModel\User\UserIdentifier;
use Neos\ContentRepository\SharedModel\Workspace\WorkspaceDescription;
use Neos\ContentRepository\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\SharedModel\Workspace\WorkspaceTitle;

/**
 * Node Migrations are manually written adjustments to the Node tree;
 * stored in "Migrations/ContentRepository" in a package.
 *
 * They are used to transform properties on nodes, or change the dimension space points in the system to others.
 *
 * Internally, these migrations can be applied on three levels:
 *
 * - globally, like changing dimensions
 * - on a NodeAggregate, like changing a NodeAggregate type
 * - on a (materialized) Node, like changing node properties.
 *
 * In a single migration, only transformations belonging to a single "level" can be applied;
 * as otherwise, the order (and semantics) becomes non-obvious.
 *
 * All migrations are applied in an empty, new ContentStream,
 * which is forked off the target workspace where the migrations are done.
 * This way, migrations can be easily rolled back by discarding the content stream instead of publishing it.
 *
 * A migration file is structured like this:
 * migrations: [
 *   {filters: ... transformations: ...},
 *   {filters: ... transformations: ...}
 * ]
 *
 * Every pair of filters/transformations is a "submigration". Inside a submigration,
 * you'll operate on the result state of all *previous* submigrations;
 * but you do not see the modified state of the current submigration while you are running it.
 */
class NodeMigrationService implements ContentRepositoryServiceInterface
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly FiltersFactory $filterFactory,
        private readonly TransformationsFactory $transformationFactory
    )
    {
    }

    public function executeMigration(ExecuteMigration $command): void
    {
        $workspace = $this->contentRepository->getWorkspaceFinder()->findOneByName($command->getWorkspaceName());
        if ($workspace === null) {
            throw new WorkspaceDoesNotExist(sprintf(
                'The workspace %s does not exist',
                $command->getWorkspaceName()
            ), 1611688225);
        }

        foreach ($command->getMigrationConfiguration()->getMigration() as $step => $migrationDescription) {
            $contentStreamForWriting = $command->getOrCreateContentStreamIdentifierForWriting($step);
            $this->contentRepository->handle(
                new CreateWorkspace(
                    WorkspaceName::fromString($contentStreamForWriting->jsonSerialize()),
                    $workspace->getWorkspaceName(),
                    WorkspaceTitle::fromString($contentStreamForWriting->jsonSerialize()),
                    WorkspaceDescription::fromString(''),
                    UserIdentifier::forSystemUser(),
                    $contentStreamForWriting,
                )
            )->block();
            /** array $migrationDescription */
            $this->executeSubMigrationAndBlock(
                $migrationDescription,
                $workspace->getCurrentContentStreamIdentifier(),
                $contentStreamForWriting
            );
        }
    }

    /**
     * Execute a single "filters / transformation" pair, i.e. a single sub-migration
     *
     * @param array<string,mixed> $migrationDescription
     * @throws MigrationException
     */
    protected function executeSubMigrationAndBlock(
        array $migrationDescription,
        ContentStreamIdentifier $contentStreamForReading,
        ContentStreamIdentifier $contentStreamForWriting
    ): void
    {
        $filters = $this->filterFactory->buildFilterConjunction($migrationDescription['filters'] ?? []);
        $transformations = $this->transformationFactory->buildTransformation(
            $migrationDescription['transformations'] ?? []
        );

        if ($transformations->containsMoreThanOneTransformationType()) {
            throw new InvalidMigrationFilterSpecified('more than one transformation type', 1617389468);
        }

        if (
            $transformations->containsGlobal()
            && ($filters->containsNodeAggregateBased() || $filters->containsNodeBased())
        ) {
            throw new InvalidMigrationFilterSpecified(
                'Global transformations are only supported without any filters',
                1617389474
            );
        }

        if ($transformations->containsNodeAggregateBased() && $filters->containsNodeBased()) {
            throw new InvalidMigrationFilterSpecified(
                'NodeAggregate Based transformations are only supported without any node based filters',
                1617389479
            );
        }

        if ($transformations->containsGlobal()) {
            $transformations->executeGlobalAndBlock($contentStreamForReading, $contentStreamForWriting);
        } elseif ($transformations->containsNodeAggregateBased()) {
            foreach ($this->contentRepository->getContentGraph()->findUsedNodeTypeNames() as $nodeTypeName) {
                foreach (
                    $this->contentRepository->getContentGraph()->findNodeAggregatesByType(
                        $contentStreamForReading,
                        $nodeTypeName
                    ) as $nodeAggregate
                ) {
                    if ($filters->matchesNodeAggregate($nodeAggregate)) {
                        $transformations->executeNodeAggregateBasedAndBlock($nodeAggregate, $contentStreamForWriting);
                    }
                }
            }
        } elseif ($transformations->containsNodeBased()) {
            foreach ($this->contentRepository->getContentGraph()->findUsedNodeTypeNames() as $nodeTypeName) {
                foreach (
                    $this->contentRepository->getContentGraph()->findNodeAggregatesByType(
                        $contentStreamForReading,
                        $nodeTypeName
                    ) as $nodeAggregate
                ) {
                    // we *also* apply the node-aggregate-based filters on the node based transformations,
                    // so that you can filter Nodes e.g. based on node type
                    if ($filters->matchesNodeAggregate($nodeAggregate)) {
                        foreach ($nodeAggregate->getOccupiedDimensionSpacePoints() as $originDimensionSpacePoint) {
                            $node = $nodeAggregate->getNodeByOccupiedDimensionSpacePoint($originDimensionSpacePoint);
                            // The node at $contentStreamIdentifier and $originDimensionSpacePoint
                            // *really* exists at this point, and is no shine-through.

                            $coveredDimensionSpacePoints = $nodeAggregate->getCoverageByOccupant(
                                $originDimensionSpacePoint
                            );

                            if ($filters->matchesNode($node)) {
                                $transformations->executeNodeBasedAndBlock(
                                    $node,
                                    $coveredDimensionSpacePoints,
                                    $contentStreamForWriting
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}