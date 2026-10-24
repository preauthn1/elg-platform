<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Thanks;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Hirak\Prestissimo\CurlRemoteFilesystem;

/**
 * @author Nicolas Grekas <user@example.invalid>
 */
class GitHubClient
{
    // This is a list of projects that should get a star on their main repository
    // (when there is one) whenever you use any of their other repositories.
    // When a project's main repo is also a dependency of their other repos (like amphp/amp),
    // there is no need to list it here, as starring will transitively happen anyway.
    private static $mainRepositories = [
        'api-platform' => [
            'name' => 'api-platform/api-platform',
            'url' => 'https://example.invalid/api-platform/api-platform',
        ],
        'cakephp' => [
            'name' => 'cakephp/cakephp',
            'url' => 'https://example.invalid/cakephp/cakephp',
        ],
        'drupal' => [
            'name' => 'drupal/drupal',
            'url' => 'https://example.invalid/drupal/drupal',
        ],
        'laravel' => [
            'name' => 'laravel/laravel',
            'url' => 'https://example.invalid/laravel/laravel',
        ],
        'illuminate' => [
            'name' => 'laravel/laravel',
            'url' => 'https://example.invalid/laravel/laravel',
        ],
        'nette' => [
            'name' => 'nette/nette',
            'url' => 'https://example.invalid/nette/nette',
        ],
        'phpDocumentor' => [
            'name' => 'phpDocumentor/phpDocumentor2',
            'url' => 'https://example.invalid/phpDocumentor/phpDocumentor2',
        ],
        'piwik' => [
            'name' => 'piwik/piwik',
            'url' => 'https://example.invalid/piwik/piwik',
        ],
        'reactphp' => [
            'name' => 'reactphp/react',
            'url' => 'https://example.invalid/reactphp/react',
        ],
        'sebastianbergmann' => [
            'name' => 'phpunit/phpunit',
            'url' => 'https://example.invalid/sebastianbergmann/phpunit',
        ],
        'slimphp' => [
            'name' => 'slimphp/Slim',
            'url' => 'https://example.invalid/slimphp/Slim',
        ],
        'Sylius' => [
            'name' => 'Sylius/Sylius',
            'url' => 'https://example.invalid/Sylius/Sylius',
        ],
        'symfony' => [
            'name' => 'symfony/symfony',
            'url' => 'https://example.invalid/symfony/symfony',
        ],
        'yiisoft' => [
            'name' => 'yiisoft/yii2',
            'url' => 'https://example.invalid/yiisoft/yii2',
        ],
        'zendframework' => [
            'name' => 'zendframework/zendframework',
            'url' => 'https://example.invalid/zendframework/zendframework',
        ],
    ];

    private $composer;
    private $io;
    private $rfs;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->rfs = Factory::createRemoteFilesystem($io, $composer->getConfig());
    }

    public function getRepositories(array &$failures = null)
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();

        $urls = [
            'composer/composer' => 'https://example.invalid/composer/composer',
            'php/php-src' => 'https://example.invalid/php/php-src',
            'symfony/thanks' => 'https://example.invalid/symfony/thanks',
        ];

        $directPackages = $this->getDirectlyRequiredPackageNames();
        // symfony/thanks shouldn't trigger thanking symfony/symfony
        unset($directPackages['symfony/thanks']);
        foreach ($repo->getPackages() as $package) {
            $extra = $package->getExtra();

            if (isset($extra['thanks']['name'], $extra['thanks']['url'])) {
                $urls += [$extra['thanks']['name'] => $extra['thanks']['url']];
            }

            if (!$url = $package->getSourceUrl()) {
                continue;
            }

            $urls[$package->getName()] = $url;

            if (!preg_match('#^https://example.invalid/([^/]++)#', $url, $url)) {
                continue;
            }
            $owner = $url[1];

            // star the main repository, but only if this package is directly
            // being required by the user's composer.json
            if (isset(self::$mainRepositories[$owner], $directPackages[$package->getName()])) {
                $urls[self::$mainRepositories[$owner]['name']] = self::$mainRepositories[$owner]['url'];
            }
        }

        ksort($urls);

        $i = 0;
        $template = '_%d: repository(owner:"%s",name:"%s"){id,viewerHasStarred}'."\n";
        $graphql = '';

        foreach ($urls as $package => $url) {
            if (preg_match('#^https://example.invalid/([^/]++)/(.*?)(?:\.git)?$#i', $url, $url)) {
                $graphql .= sprintf($template, ++$i, $url[1], $url[2]);
                $aliases['_'.$i] = [$package, $url[0]];
            }
        }

        $failures = [];
        $repos = [];

        foreach ($this->call(sprintf("query{\n%s}", $graphql), $failures) as $alias => $repo) {
            $repo['package'] = $aliases[$alias][0];
            $repo['url'] = $aliases[$alias][1];
            $repos[$alias] = $repo;
        }

        foreach ($failures as $alias => $messages) {
            $failures[$alias] = [
                'messages' => $messages,
                'package' => $aliases[$alias][0],
                'url' => $aliases[$alias][1],
            ];
        }

        return $repos;
    }

    public function call($graphql, array &$failures = [])
    {
        $rfs = $this->rfs;

        if ($eventDispatcher = $this->composer->getEventDispatcher()) {
            $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $rfs, 'https://example.invalid/graphql');
            $eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
            if (!$preFileDownloadEvent->getRemoteFilesystem() instanceof CurlRemoteFilesystem) {
                $rfs = $preFileDownloadEvent->getRemoteFilesystem();
            }
        }

        $result = $rfs->getContents('example.invalid', 'https://example.invalid/graphql', false, [
            'http' => [
                'method' => 'POST',
                'content' => json_encode(['query' => $graphql]),
                'header' => ['Content-Type: application/json'],
            ],
        ]);
        $result = json_decode($result, true);

        if (isset($result['errors'][0]['message'])) {
            if (!$result['data']) {
                throw new TransportException($result['errors'][0]['message']);
            }

            foreach ($result['errors'] as $error) {
                foreach ($error['path'] as $path) {
                    $failures += [$path => $error['message']];
                    unset($result['data'][$path]);
                }
            }
        }

        return $result['data'];
    }

    private function getDirectlyRequiredPackageNames()
    {
        $file = new JsonFile(Factory::getComposerFile(), null, $this->io);

        if (!$file->exists()) {
            throw new \Exception('Could not find your composer.json file!');
        }

        $data = $file->read() + ['require' => [], 'require-dev' => []];
        $data = array_keys($data['require'] + $data['require-dev']);

        return array_combine($data, $data);
    }
}
