<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Commands;

use Calevans\StaticForgePodcast\Services\MediaInspector;
use Calevans\StaticForgePodcast\Services\SafePath;
use EICC\Utils\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[AsCommand(
    name: 'media:inspect',
    description: 'Inspect the media file referenced in a markdown file\'s frontmatter'
)]
final class InspectMediaCommand extends Command
{
    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the markdown file')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Persist the inspected values into the frontmatter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = (string) $input->getArgument('file');
        $write = (bool) $input->getOption('write');

        if (!is_file($filePath)) {
            $io->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $io->error("Failed to read file: {$filePath}");
            return Command::FAILURE;
        }

        if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n(.*)$/s', $content, $matches) !== 1) {
            $io->error("No valid YAML frontmatter found in {$filePath}");
            return Command::FAILURE;
        }

        $frontmatterRaw = $matches[1];
        $body = $matches[2];

        try {
            $frontmatter = Yaml::parse($frontmatterRaw);
        } catch (Throwable $e) {
            $io->error('Failed to parse YAML frontmatter: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!is_array($frontmatter)) {
            $io->error('Frontmatter did not parse to a mapping.');
            return Command::FAILURE;
        }

        $mediaFile = $frontmatter['audio_file'] ?? $frontmatter['video_file'] ?? null;
        if (!is_string($mediaFile) || $mediaFile === '') {
            $io->error("No 'audio_file' or 'video_file' found in frontmatter.");
            return Command::FAILURE;
        }

        $io->section("Inspecting Media: {$mediaFile}");

        $tempFile = null;

        try {
            $inspectPath = $this->resolveInspectPath($mediaFile, $tempFile, $io);
            if ($inspectPath === null) {
                return Command::FAILURE;
            }

            $mediaInfo = (new MediaInspector())->inspect($inspectPath);

            $io->success('Analysis Complete:');
            $io->table(
                ['Property', 'Value'],
                [
                    ['Size', number_format($mediaInfo->size) . ' bytes'],
                    ['Type', $mediaInfo->mimeType],
                    ['Duration', $mediaInfo->formattedDuration],
                ]
            );

            if (!$write) {
                $io->note('Read-only mode - pass --write to persist these values into the frontmatter.');
                return Command::SUCCESS;
            }

            $changes = [
                'audio_size' => $mediaInfo->size,
                'audio_type' => $mediaInfo->mimeType,
                'itunes_duration' => $mediaInfo->formattedDuration,
            ];

            $newFrontmatterRaw = $this->spliceFrontmatter($frontmatterRaw, $changes);
            $newContent = "---\n{$newFrontmatterRaw}---\n{$body}";

            if (!$this->writeAtomically($filePath, $newContent)) {
                $io->error("Failed to write updated frontmatter to {$filePath}");
                return Command::FAILURE;
            }

            $io->success("Updated {$filePath}");

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error('Analysis failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            if ($tempFile !== null && is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    private function resolveInspectPath(string $mediaFile, ?string &$tempFile, SymfonyStyle $io): ?string
    {
        if (preg_match('~^https?://~i', $mediaFile) === 1) {
            $io->text('Remote file detected. Downloading...');
            $downloadTarget = tempnam(sys_get_temp_dir(), 'sf_media_');
            if ($downloadTarget === false) {
                $io->error('Could not create temporary file.');
                return null;
            }

            $tempFile = $downloadTarget;

            if (!$this->downloadFile($mediaFile, $downloadTarget, $io)) {
                return null;
            }

            return $downloadTarget;
        }

        $sourceDir = rtrim((string) $this->container->getVariable('SOURCE_DIR'), '/\\');
        $outputDir = rtrim((string) $this->container->getVariable('OUTPUT_DIR'), '/\\');

        foreach ([$sourceDir, $outputDir] as $root) {
            if ($root === '') {
                continue;
            }

            $candidate = SafePath::resolveExisting($root . '/' . ltrim($mediaFile, '/\\'), $root);

            if ($candidate !== null && is_file($candidate)) {
                return $candidate;
            }
        }

        // No bare-$mediaFile fallback on purpose: this value is frontmatter,
        // not argv, so honouring an absolute or ../-escaping path here would
        // hand getID3 any file on the box and (with --write) commit its size
        // into a tracked file - undoing the containment check just above.
        $io->error("Local media file not found inside SOURCE_DIR or OUTPUT_DIR: {$mediaFile}");
        return null;
    }

    /**
     * Walks redirects by hand, vetting every hop's host, because libcurl's own
     * CURLOPT_FOLLOWLOCATION only ever exposes the FINAL hop to
     * CURLINFO_PRIMARY_IP - a chain that detours through 127.0.0.1 and ends
     * somewhere public would pass an end-of-transfer check unnoticed.
     */
    private function downloadFile(string $url, string $dest, SymfonyStyle $io): bool
    {
        $maxRedirects = 3;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            if (!$this->hostAllowed($url, $io)) {
                return false;
            }

            $next = $this->fetchOnce($url, $dest, $io);

            if ($next === null) {
                return false;
            }

            if ($next === '') {
                return true;
            }

            $url = $next;
        }

        $io->error('Too many redirects while fetching media.');
        return false;
    }

    /**
     * Fetches exactly one hop into $dest. Returns '' when the body was
     * downloaded, the absolute redirect target when the server redirected, or
     * null on failure.
     */
    private function fetchOnce(string $url, string $dest, SymfonyStyle $io): ?string
    {
        $fp = fopen($dest, 'w+');
        if ($fp === false) {
            $io->error('Could not open temporary file for writing.');
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $io->error('Could not initialize cURL.');
            fclose($fp);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR => true,
            CURLOPT_PROTOCOLS_STR => 'https,http',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_MAXFILESIZE => 2 * 1024 * 1024 * 1024,
        ]);

        $progressBar = $io->createProgressBar();
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt(
            $ch,
            CURLOPT_PROGRESSFUNCTION,
            function (
                \CurlHandle $handle,
                int $downloadSize,
                int $downloaded,
                int $uploadSize,
                int $uploaded
            ) use (
                $progressBar
            ): int {
                if ($downloadSize > 0) {
                    $progressBar->setMaxSteps($downloadSize);
                    $progressBar->setProgress($downloaded);
                }

                return 0;
            }
        );

        $success = curl_exec($ch);
        $progressBar->finish();
        $io->newLine();

        if ($success !== true) {
            $io->error('Download failed: ' . curl_error($ch));
            curl_close($ch);
            fclose($fp);
            return null;
        }

        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        fclose($fp);

        // Defence in depth behind hostAllowed(): catches a rebind that landed
        // between the pre-flight resolve and the connect.
        if ($primaryIp !== '' && $this->isPrivateOrLinkLocal($primaryIp)) {
            $io->error('Refusing media from a private or link-local address.');
            return null;
        }

        return $redirectUrl !== '' ? $redirectUrl : '';
    }

    /**
     * Resolves the URL's host and refuses anything that points inside the
     * network the build is running on. Called before every hop, so a redirect
     * chain cannot bounce through an internal address on its way to a public
     * one. The error text never names the resolved IP - that would turn this
     * command into an internal host scanner for anyone who can open a PR.
     */
    private function hostAllowed(string $url, SymfonyStyle $io): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $io->error('Media URL has no host.');
            return false;
        }

        foreach ($this->resolveHost($host) as $ip) {
            if ($this->isPrivateOrLinkLocal($ip)) {
                $io->error('Refusing media from a private or link-local address.');
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private function isPrivateOrLinkLocal(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Splices only the changed keys into the raw frontmatter text instead of
     * re-dumping the whole thing through Yaml::dump(), which would destroy
     * comments, key order, and quoting.
     *
     * @param array<string, int|string> $changes
     */
    private function spliceFrontmatter(string $rawFrontmatter, array $changes): string
    {
        $lines = explode("\n", $rawFrontmatter);
        $handled = [];

        foreach ($lines as $index => $line) {
            foreach ($changes as $key => $value) {
                if (preg_match('/^' . preg_quote($key, '/') . '\s*:/', $line) === 1) {
                    $lines[$index] = $key . ': ' . $this->dumpScalar($value);
                    $handled[$key] = true;
                }
            }
        }

        foreach ($changes as $key => $value) {
            if (!isset($handled[$key])) {
                $lines[] = $key . ': ' . $this->dumpScalar($value);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function dumpScalar(int|string $value): string
    {
        return rtrim(Yaml::dump($value));
    }

    private function writeAtomically(string $filePath, string $content): bool
    {
        $dir = dirname($filePath);
        $tempFile = tempnam($dir, 'sf_frontmatter_');
        if ($tempFile === false) {
            return false;
        }

        try {
            if (file_put_contents($tempFile, $content) === false) {
                return false;
            }

            // tempnam() creates the file 0600; without this the episode's
            // permissions silently become 0600 after the rename.
            $mode = fileperms($filePath);
            if ($mode !== false) {
                chmod($tempFile, $mode & 0777);
            }

            return rename($tempFile, $filePath);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
