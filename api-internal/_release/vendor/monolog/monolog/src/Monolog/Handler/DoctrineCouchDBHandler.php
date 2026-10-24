<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler;

use Monolog\Logger;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Formatter\FormatterInterface;
use Doctrine\CouchDB\CouchDBClient;

/**
 * CouchDB handler for Doctrine CouchDB ODM
 *
 * @author Markus Bachmann <user@example.invalid>
 */
class DoctrineCouchDBHandler extends AbstractProcessingHandler
{
    private $client;

    public function __construct(CouchDBClient $client, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->client = $client;
        parent::__construct($level, $bubble);
    }

    /**
     * {@inheritDoc}
     */
    protected function write(array $record): void
    {
        $this->client->postDocument($record['formatted']);
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new NormalizerFormatter;
    }
}
