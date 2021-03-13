<?php

namespace PierreMiniggio\YoutubeToGithub;

use Illuminate\Support\Str;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\YoutubeToGithub\Connection\DatabaseConnectionFactory;
use PierreMiniggio\YoutubeToGithub\Repository\LinkedChannelRepository;
use PierreMiniggio\YoutubeToGithub\Repository\NonUploadedVideoRepository;
use PierreMiniggio\YoutubeToGithub\Repository\RepoToCreateRepository;

class App
{

    public function run(): int
    {

        $code = 0;

        $config = require(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php');

        if (empty($config['db'])) {
            echo 'No DB config';

            return $code;
        }

        $databaseFetcher = new DatabaseFetcher((new DatabaseConnectionFactory())->makeFromConfig($config['db']));
        $channelRepository = new LinkedChannelRepository($databaseFetcher);
        $nonUploadedVideoRepository = new NonUploadedVideoRepository($databaseFetcher);
        $repoToCreateRepository = new RepoToCreateRepository($databaseFetcher);

        $linkedChannels = $channelRepository->findAll();

        if (! $linkedChannels) {
            echo 'No linked channels';

            return $code;
        }

        foreach ($linkedChannels as $linkedChannel) {
            echo PHP_EOL . PHP_EOL . 'Checking account ' . $linkedChannel['g_id'] . '...';

            $reposToCreate = $nonUploadedVideoRepository->findByGithubAndYoutubeChannelIds(
                $linkedChannel['g_id'],
                $linkedChannel['y_id']
            );
            echo PHP_EOL . count($reposToCreate) . ' repos to create :' . PHP_EOL;

            foreach ($reposToCreate as $repoToCreate) {
                echo PHP_EOL . 'Posting ' . $repoToCreate['title'] . ' ...';

                $sluggedTitle = substr(Str::slug($repoToCreate['title']), 0, 100);

                $curl = curl_init('https://api.github.com/user/repos');
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => json_encode([
                        'name' => $sluggedTitle,
                        'description' => 'Nouvelle video ' . $repoToCreate['url'],
                        'auto_init' => false,
                        'private' => false
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: token ' . $linkedChannel['api_token']
                    ],
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.150 Safari/537.36 OPR/74.0.3911.160'
                ]);

                $res = curl_exec($curl);
                $jsonResponse = json_decode($res, true);

                if (! empty($res) && ! empty($jsonResponse) && ! empty($jsonResponse['id'])) {
                    $repoToCreateRepository->insertRepoIfNeeded(
                        $jsonResponse['id'],
                        $jsonResponse['url'],
                        $linkedChannel['g_id'],
                        $repoToCreate['id']
                    );
                    echo PHP_EOL . $repoToCreate['title'] . ' posted !';
                } else {
                    echo PHP_EOL . 'Error while creating ' . $repoToCreate['title'] . ' : ' . $res;
                }
            }

            echo PHP_EOL . PHP_EOL . 'Done for account ' . $linkedChannel['g_id'] . ' !';
        }

        return $code;
    }
}
