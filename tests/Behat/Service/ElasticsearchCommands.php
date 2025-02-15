<?php

/*
 * This file was created by developers working at BitBag
 * Do you need more information about us and what we do? Visit our https://bitbag.io website!
 * We are hiring developers from all over the world. Join us and start your new, exciting adventure and become part of us: https://bitbag.io/career
*/

declare(strict_types=1);

namespace Tests\BitBag\SyliusCrossSellingPlugin\Behat\Service;

use FOS\ElasticaBundle\Event\IndexPopulateEvent;
use FOS\ElasticaBundle\Event\TypePopulateEvent;
use FOS\ElasticaBundle\Index\IndexManager;
use FOS\ElasticaBundle\Index\Resetter;
use FOS\ElasticaBundle\Persister\PagerPersisterInterface;
use FOS\ElasticaBundle\Persister\PagerPersisterRegistry;
use FOS\ElasticaBundle\Provider\PagerProviderRegistry;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ElasticsearchCommands
{
    /** @var EventDispatcherInterface */
    private $dispatcher;

    /** @var IndexManager */
    private $indexManager;

    /** @var PagerProviderRegistry */
    private $pagerProviderRegistry;

    /** @var PagerPersisterRegistry */
    private $pagerPersisterRegistry;

    /** @var PagerPersisterInterface */
    private $pagerPersister;

    /** @var Resetter */
    private $resetter;

    public function __construct(
        EventDispatcherInterface $dispatcher,
        IndexManager $indexManager,
        PagerProviderRegistry $pagerProviderRegistry,
        PagerPersisterRegistry $pagerPersisterRegistry,
        Resetter $resetter
    ) {
        $this->dispatcher = $dispatcher;
        $this->indexManager = $indexManager;
        $this->pagerProviderRegistry = $pagerProviderRegistry;
        $this->pagerPersisterRegistry = $pagerPersisterRegistry;
        $this->resetter = $resetter;
    }

    public function resetAllIndexes(): void
    {
        $this->resetter->resetAllIndexes(false, true);
    }

    public function populateAllIndexes(): void
    {
        $this->pagerPersister = $this->pagerPersisterRegistry->getPagerPersister('in_place');

        $indexes = array_keys($this->indexManager->getAllIndexes());

        $options = [
            'delete' => true,
            'reset' => true,
        ];

        foreach ($indexes as $index) {
            $event = new IndexPopulateEvent($index, true, $options);
            $this->dispatcher->dispatch($event, IndexPopulateEvent::PRE_INDEX_POPULATE);

            if ($event->isReset()) {
                $this->resetter->resetIndex($index, true);
            }

            $types = array_keys($this->pagerProviderRegistry->getIndexProviders($index));
            foreach ($types as $type) {
                $this->populateIndexType($index, $type, false, $event->getOptions());
            }

            $this->dispatcher->dispatch($event, IndexPopulateEvent::POST_INDEX_POPULATE);

            $this->refreshIndex($index);
        }
    }

    private function populateIndexType(
        string $index,
        string $type,
        bool $reset,
        array $options
    ): void
    {
        $event = new TypePopulateEvent($index, $type, $reset, $options);
        $this->dispatcher->dispatch($event, TypePopulateEvent::PRE_TYPE_POPULATE);

        if ($event->isReset()) {
            $this->resetter->resetIndexType($index, $type);
        }

        $provider = $this->pagerProviderRegistry->getProvider($index, $type);

        $pager = $provider->provide($options);

        $options['indexName'] = $index;
        $options['typeName'] = $type;

        $this->pagerPersister->insert($pager, $options);

        $this->dispatcher->dispatch($event, TypePopulateEvent::POST_TYPE_POPULATE);

        $this->refreshIndex($index);
    }

    private function refreshIndex(string $index): void
    {
        $this->indexManager->getIndex($index)->refresh();
    }
}
