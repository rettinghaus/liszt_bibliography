<?php

declare(strict_types=1);

/*
 * This file is part of the Liszt Catalog Raisonne project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 */

namespace Slub\LisztBibliography\Command;

use Elasticsearch\Client;
use Hedii\ZoteroApi\ZoteroApi;
use Illuminate\Support\Collection;
use Slub\LisztCommon\Common\ElasticClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class IndexCommand extends Command
{

    const HEADER_FIELDS = [
        [
            'field' => 'creator.firstName',
            'conditionField' => 'creator.type',
            'conditionValue' => 'author',
            'conditionRelation' => 'eq'
        ],
        [
            'field' => 'creator.lastName',
            'conditionField' => 'creator.type',
            'conditionValue' => 'author',
            'conditionRelation' => 'eq'
        ]
    ];
    const BODY_FIELDS = [
        [
            'field' => 'title'
        ],
        [
            'field' => 'shortTitle'
        ],
    ];
    const FOOTER_FIELDS = [
        [
            'field' => 'creator.firstName',
            'conditionField' => 'creator.type',
            'conditionValue' => 'editor',
            'conditionRelation' => 'eq'
        ],
        [
            'field' => 'creator.lastName',
            'conditionField' => 'creator.type',
            'conditionValue' => 'editor',
            'conditionRelation' => 'eq'
        ],
        [
            'field' => 'publicationTitle',
            'conditionField' => 'publicationTitle',
            'conditionValue' => '',
            'conditionRelation' => 'neq'
        ],
        [
            'field' => 'bookTitle',
            'conditionField' => 'bookTitle',
            'conditionValue' => '',
            'conditionRelation' => 'neq'
        ],
        [
            'field' => 'university',
            'conditionField' => 'university',
            'conditionValue' => '',
            'conditionRelation' => 'neq'
        ],
        [
            'field' => 'volume',
            'conditionField' => 'volume',
            'conditionValue' => '',
            'conditionRelation' => 'neq'
        ],
        [
            'field' => 'issue',
            'conditionField' => 'issue',
            'conditionValue' => '',
            'conditionRelation' => 'neq'
        ],
        [
            'field' => 'place',
            'conditionField' => 'place',
            'conditionValue' => '',
            'conditionRelation' => 'neq'
        ],
        [
            'field' => 'date',
            'conditionField' => 'date',
            'conditionValue' => '',
            'conditionRelation' => 'neq'
        ]
    ];
    const SEARCHABLE_FIELDS = [
        'author',
        'title',
        'university',
        'bookTitle',
        'series',
        'publicationTitle',
        'place',
        'date',
        'shortTitle'
    ];
    const BOOSTED_FIELDS = [
        'title'
    ];

    protected ZoteroApi $bibApi;
    protected Collection $bibliographyItems;
    protected Client $client;
    protected array $extConf;
    protected SymfonyStyle $io;
    protected ZoteroApi $localeApi;
    protected array $locales;

    protected function configure(): void
    {
        $this->setDescription('Create elasticsearch index from zotero bibliography');
    }

    protected function initialize(InputInterface $input, OutputInterface $output) {
		$this->extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');
        $this->client = ElasticClientBuilder::getClient();
        $this->bibApi = new ZoteroApi($this->extConf['zoteroApiKey']);
        $this->localeApi = new ZoteroApi($this->extConf['zoteroApiKey']);
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getDescription());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->section('Fetching Bibliography Data');
        $this->fetchBibliography();
        $this->io->section('Committing Bibliography Data');
        $this->commitBibliography();
        $this->io->section('Committing Locale Data');
        $this->commitLocales();
        return 0;
    }

    protected function fetchBibliography(): void
    {
        // fetch locales
        $response = $this->localeApi->
            raw('https://api.zotero.org/schema?format=json')->
            send();
        $this->locales = $response->getBody()['locales'];

        // get bulk size and total size
        $bulkSize = (int) $this->extConf['zoteroBulkSize'];
        $response = $this->bibApi->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            limit(1)->
            send();
        $total = (int) $response->getHeaders()['Total-Results'][0];

        // fetch bibliography items bulkwise
        $this->io->progressStart($total);
        $collection = new Collection($response->getBody());
        $this->bibliographyItems = $collection->pluck('data');

        $cursor = $bulkSize;
        while ($cursor < $total) {
            $this->io->progressAdvance($bulkSize);
            $response = $this->bibApi->
                group($this->extConf['zoteroGroupId'])->
                items()->
                top()->
                start($cursor)->
                limit($bulkSize)->
                send();
            $collection = new Collection($response->getBody());
            $this->bibliographyItems = $this->bibliographyItems->
                concat($collection->pluck('data'));
            $cursor += $bulkSize;
        }
        $this->io->progressFinish();
    }

    protected function commitBibliography(): void
    {
        $index = $this->extConf['elasticIndexName'];
        $this->io->text('Committing the ' . $index . ' index');

        $this->io->progressStart(count($this->bibliographyItems));
        if ($this->client->indices()->exists(['index' => $index])) {
            $this->client->indices()->delete(['index' => $index]);
            $this->client->indices()->create(['index' => $index]);
        }

        $params = [ 'body' => [] ];
        $bulkCount = 0;
        foreach ($this->bibliographyItems as $document) {
            $this->io->progressAdvance();
            $params['body'][] = [ 'index' =>
                [
                    '_index' => $index,
                    '_id' => $document['key']
                ]
            ];
            $params['body'][] = json_encode($document);

            if (!(++$bulkCount % $this->extConf['elasticBulkSize'])) {
                $this->client->bulk($params);
                $params = [ 'body' => [] ];
            }
        }
        $this->io->progressFinish();
        $this->client->bulk($params);

        $this->io->text('done');
    }

    protected function commitLocales(): void
    {
        $localeIndex = $this->extConf['elasticLocaleIndexName'];
        $this->io->text('Committing the ' . $localeIndex . ' index');

        if ($this->client->indices()->exists(['index' => $localeIndex])) {
            $this->client->indices()->delete(['index' => $localeIndex]);
            $this->client->indices()->create(['index' => $localeIndex]);
        }

        $params = [ 'body' => [] ];
        foreach ($this->locales as $key => $locale) {
            $params['body'][] = [ 'index' =>
                [
                    '_index' => $localeIndex,
                    '_id' => $key
                ]
            ];
            $params['body'][] = json_encode($locale);

        }
        $this->client->bulk($params);

        $this->io->text('done');
    }
}
