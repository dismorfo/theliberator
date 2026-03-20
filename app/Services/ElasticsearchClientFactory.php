<?php

namespace App\Services;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

class ElasticsearchClientFactory
{
    public function make(): Client
    {
        return ClientBuilder::create()
            ->setHosts([config('services.elasticsearch.host', 'http://localhost:9200')])
            ->build();
    }
}
