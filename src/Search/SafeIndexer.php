<?php

namespace Base\Video\Search;

use Psr\Log\LoggerInterface;
use Typesense\Bundle\EventListener\TypesenseIndexer;

/**
 * glitchr/typesense-bundle's indexer, made safe for a search server that is down: whatever the client throws (the
 * bundle catches its own and php-http's exceptions only - a PSR-18 network error from symfony/http-client went
 * through and failed the page, or the fixtures, that saved a film), the index misses a change, the save goes on, a
 * warning is logged and the bundle's own report (a flash) is made once. `bin/console typesense:action upsert` fills
 * the index again. What it sent is forgotten either way, so a long-running worker does not send it twice.
 *
 * Every indexer of the bundle is this class where the bundle is installed (Doctrine's listener and the one the
 * neutral lifecycle events reach): VideoBundle's compiler pass sets it; without the bundle, nothing is indexed and
 * omnibase/video works without search.
 */
class SafeIndexer extends TypesenseIndexer
{
    private ?LoggerInterface $logger = null;

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function flush(): void
    {
        $transactions = $this->transactions;
        $this->transactions = [];
        $failed = false;
        foreach ($transactions as $transaction) {
            try {
                $transaction->commit();
            } catch (\Throwable $e) {
                if (!$failed) {
                    $this->logger?->warning('The search index missed a change (Typesense): {message}', ['message' => $e->getMessage()]);
                    try {
                        $this->onError && ($this->onError)($e);
                    } catch (\Throwable) {
                    }
                }
                $failed = true;
            }
        }
        if ($transactions) {
            try {
                $this->cache->clear();
            } catch (\Throwable) {
            }
        }
    }
}
