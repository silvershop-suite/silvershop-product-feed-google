<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Task;

use SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory;
use SilverShop\ProductFeed\Google\Service\GoogleTaxonomy;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * (Re)seeds the bundled Google product taxonomy into {@link GoogleShoppingCategory}. dev/build already seeds it once
 * when the table is empty; run this to refresh after dropping in a newer taxonomy file, or with {@code --fresh} to
 * rebuild from scratch.
 */
class SeedGoogleShoppingCategoryTask extends BuildTask
{
    protected static string $commandName = 'ProductFeedGoogle-SeedTaxonomy';

    protected string $title = 'Seed the Google product taxonomy';

    protected static string $description = 'Seeds/refreshes the bundled Google product taxonomy categories.';

    public function getOptions(): array
    {
        return [
            new InputOption('fresh', null, InputOption::VALUE_NONE, 'Delete existing taxonomy categories first, then reseed.'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if ($input->getOption('fresh')) {
            DB::query('DELETE FROM "' . GoogleShoppingCategory::singleton()->baseTable() . '"');
            $output->writeln('Cleared existing taxonomy categories.');
        }

        $count = GoogleTaxonomy::create()->seed();
        $output->writeln(sprintf('Seeded %d Google product taxonomy categories.', $count));

        return Command::SUCCESS;
    }
}
