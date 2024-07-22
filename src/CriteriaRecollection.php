<?php

declare(strict_types=1);

/*
 * This file is part of rekalogika/collections package.
 *
 * (c) Priyadi Iman Nurcahyo <https://rekalogika.dev>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Rekalogika\Domain\Collections;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\Common\Collections\ReadableCollection;
use Doctrine\Common\Collections\Selectable;
use Rekalogika\Contracts\Collections\Exception\UnexpectedValueException;
use Rekalogika\Contracts\Collections\ReadableRecollection;
use Rekalogika\Domain\Collections\Common\Configuration;
use Rekalogika\Domain\Collections\Common\Count\CountStrategy;
use Rekalogika\Domain\Collections\Common\Internal\ParameterUtil;
use Rekalogika\Domain\Collections\Common\KeyTransformer\KeyTransformer;
use Rekalogika\Domain\Collections\Common\Pagination;
use Rekalogika\Domain\Collections\Common\Trait\ReadableRecollectionTrait;
use Rekalogika\Domain\Collections\Common\Trait\SafeCollectionTrait;
use Rekalogika\Domain\Collections\Trait\CriteriaReadableTrait;
use Rekalogika\Domain\Collections\Trait\RecollectionDxTrait;
use Rekalogika\Domain\Collections\Trait\RecollectionPageableTrait;

/**
 * @template TKey of array-key
 * @template T
 * @implements ReadableRecollection<TKey,T>
 */
class CriteriaRecollection implements ReadableRecollection
{
    /** @use RecollectionPageableTrait<TKey,T> */
    use RecollectionPageableTrait;

    /** @use SafeCollectionTrait<TKey,T> */
    use SafeCollectionTrait;

    /**
     * @use ReadableRecollectionTrait<TKey,T>
     * @use CriteriaReadableTrait<TKey,T>
     */
    use ReadableRecollectionTrait, CriteriaReadableTrait {
        CriteriaReadableTrait::contains insteadof ReadableRecollectionTrait;
        CriteriaReadableTrait::containsKey insteadof ReadableRecollectionTrait;
        CriteriaReadableTrait::get insteadof ReadableRecollectionTrait;
        CriteriaReadableTrait::slice insteadof ReadableRecollectionTrait;
    }

    /** @use RecollectionDxTrait<TKey,T> */
    use RecollectionDxTrait;

    /**
     * @var null|\WeakMap<object,array<string,self<array-key,mixed>>>
     */
    private static ?\WeakMap $instances = null;

    /**
     * @var Selectable<TKey,T>
     */
    private readonly Selectable $collection;

    private readonly Criteria $criteria;

    private readonly ?string $indexBy;

    /**
     * @var int<1,max>
     */
    private readonly int $itemsPerPage;

    /**
     * @param ReadableCollection<TKey,T>|Selectable<TKey,T> $collection
     * @param int<1,max> $itemsPerPage
     * @param null|int<1,max> $softLimit
     * @param null|int<1,max> $hardLimit
     */
    final private function __construct(
        ReadableCollection|Selectable $collection,
        ?Criteria $criteria = null,
        ?string $indexBy = null,
        ?int $itemsPerPage = null,
        private readonly ?CountStrategy $count = null,
        private readonly ?int $softLimit = null,
        private readonly ?int $hardLimit = null,
        private readonly ?KeyTransformer $keyTransformer = null,
        private readonly ?Pagination $pagination = null,
    ) {
        $this->indexBy = $indexBy ?? Configuration::$defaultIndexBy;
        $this->itemsPerPage = $itemsPerPage ?? Configuration::$defaultItemsPerPage;

        // save collection

        if (!$collection instanceof Selectable) {
            throw new UnexpectedValueException('The wrapped collection must implement the Selectable interface.');
        }

        $this->collection = $collection;

        // save criteria

        $criteria = clone ($criteria ?? Criteria::create());

        if (\count($criteria->orderings()) === 0) {
            $criteria->orderBy(Configuration::$defaultOrderBy);
        }

        $this->criteria = $criteria;
    }

    /**
     * @template STKey of array-key
     * @template ST
     * @param ReadableCollection<STKey,ST>|Selectable<STKey,ST> $collection
     * @param int<1,max> $itemsPerPage
     * @param null|int<1,max> $softLimit
     * @param null|int<1,max> $hardLimit
     * @return static
     */
    final public static function create(
        ReadableCollection|Selectable $collection,
        ?Criteria $criteria = null,
        ?string $instanceId = null,
        ?string $indexBy = null,
        int $itemsPerPage = 50,
        ?CountStrategy $count = null,
        ?int $softLimit = null,
        ?int $hardLimit = null,
        ?KeyTransformer $keyTransformer = null,
        ?Pagination $pagination = null,
    ): ReadableRecollection {
        if (self::$instances === null) {
            /** @var \WeakMap<object,array<string,self<array-key,mixed>>> */
            $weakmap = new \WeakMap();
            // @phpstan-ignore-next-line
            self::$instances = $weakmap;
        }

        $cacheKey = hash('xxh128', serialize([
            $instanceId ?? $criteria,
            $indexBy,
            $itemsPerPage,
            $pagination,
        ]));

        if (isset(self::$instances[$collection][$cacheKey])) {
            /** @var static */
            return self::$instances[$collection][$cacheKey];
        }

        /** @psalm-suppress UnsafeGenericInstantiation */
        $newInstance = new static(
            collection: $collection,
            criteria: $criteria,
            indexBy: $indexBy,
            itemsPerPage: $itemsPerPage,
            count: $count,
            softLimit: $softLimit,
            hardLimit: $hardLimit,
            keyTransformer: $keyTransformer,
            pagination: $pagination,
        );

        if (!isset(self::$instances[$collection])) {
            // @phpstan-ignore-next-line
            self::$instances[$collection] = [];
        }

        /**
         * @psalm-suppress InvalidArgument
         * @phpstan-ignore-next-line
         */
        self::$instances[$collection][$cacheKey] = $newInstance;

        /** @var static */
        return $newInstance;
    }

    private function getCountStrategy(): CountStrategy
    {
        return $this->count ?? ParameterUtil::getDefaultCountStrategyForFullClasses();
    }

    /**
     * @return ReadableCollection<TKey,T>
     */
    private function getRealCollection(): ReadableCollection
    {
        return $this->getSafeCollection();
    }

    /**
     * @return null|int<1,max>
     */
    private function getSoftLimit(): ?int
    {
        return $this->softLimit;
    }

    /**
     * @return null|int<1,max>
     */
    private function getHardLimit(): ?int
    {
        return $this->hardLimit;
    }

    /**
     * @return non-empty-array<string,Order>
     */
    private function getOrderBy(): array
    {
        $ordering = $this->criteria->orderings();

        if ($ordering === []) {
            return ['id' => Order::Descending];
        }

        return $ordering;
    }

    /**
     * @param int<1,max> $itemsPerPage
     */
    #[\Override]
    final public function withItemsPerPage(int $itemsPerPage): static
    {
        return self::create(
            collection: $this->collection,
            criteria: $this->criteria,
            itemsPerPage: $itemsPerPage,
            count: $this->count,
            softLimit: $this->softLimit,
            hardLimit: $this->hardLimit,
            keyTransformer: $this->keyTransformer,
        );
    }

    private function getUnderlyingCountable(): \Countable
    {
        return $this->collection->matching($this->criteria);
    }

    final protected function createCriteria(): Criteria
    {
        return clone $this->criteria;
    }
}
