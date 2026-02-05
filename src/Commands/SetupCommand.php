<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class SetupCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('podcast:setup')
            ->setDescription('Initialize Podcast feature (cache, templates, etc.)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Setting up Podcast Feature...</info>');

        // Determine paths
        $projectRoot = getcwd();
        $cacheDir = $projectRoot . '/cache';
        
        // --- 1. Cache Setup ---
        if (!is_dir($cacheDir)) {
            $output->writeln("Creating cache directory: <comment>{$cacheDir}</comment>");
            if (mkdir($cacheDir, 0755, true)) {
                $output->writeln('<info>✅ Cache directory created.</info>');
            } else {
                $output->writeln('<error>❌ Failed to create cache directory.</error>');
            }
        } else {
            $output->writeln('<info>✅ Cache directory exists.</info>');
        }

        // Initialize state file if not exists
        $stateFile = $cacheDir . '/podcast_media_state.json';
        if (!file_exists($stateFile)) {
             file_put_contents($stateFile, '{}');
             $output->writeln('<info>✅ Initialized empty state file.</info>');
        } else {
            $output->writeln('<info>✅ State file exists.</info>');
        }

        // --- 2. Template Installation ---
        $this->installTemplates($projectRoot, $output);

        return Command::SUCCESS;
    }

    protected function installTemplates(string $projectRoot, OutputInterface $output): void
    {
        $configFile = $projectRoot . '/siteconfig.yaml';
        if (!file_exists($configFile)) {
            $output->writeln('<comment>⚠️ siteconfig.yaml not found. Skipping template installation.</comment>');
            return;
        }

        try {
            $config = Yaml::parseFile($configFile);
            $theme = $config['site']['theme'] ?? null;

            if (!$theme) {
                $output->writeln('<comment>⚠️ No theme defined in siteconfig.yaml. Skipping template installation.</comment>');
                return;
            }

            $templateDestDir = $projectRoot . '/templates/' . $theme;
            $templateDestFile = $templateDestDir . '/_podcast_badges.html.twig';
            
            // Source is in ../../resources/templates/ relative to this file
            $sourceDir = dirname(__DIR__, 2) . '/resources/templates';
            $sourceFile = $sourceDir . '/_podcast_badges.html.twig';

            if (!file_exists($sourceFile)) {
                 $output->writeln('<error>❌ Master template not found in package.</error>');
                 return;
            }

            if (!file_exists($templateDestDir)) {
                $output->writeln("<comment>⚠️ Theme directory templates/{$theme} does not exist. Skipping.</comment>");
                return;
            }

            if (!file_exists($templateDestFile)) {
                if (copy($sourceFile, $templateDestFile)) {
                    $output->writeln("<info>✅ Installed badge template to: templates/{$theme}/_podcast_badges.html.twig</info>");
                } else {
                    $output->writeln("<error>❌ Failed to copy template to: {$templateDestFile}</error>");
                }
            } else {
                $output->writeln("<info>ℹ️ Badge template already exists at: templates/{$theme}/_podcast_badges.html.twig</info>");
            }

        } catch (\Exception $e) {
            $output->writeln("<error>❌ Error parsing siteconfig.yaml: " . $e->getMessage() . "</error>");
        }
    }
}
