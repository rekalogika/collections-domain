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

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\Common\Collections\Selectable;
use Rekalogika\Contracts\Collections\Exception\UnexpectedValueException;
use Rekalogika\Contracts\Collections\Recollection;
use Rekalogika\Domain\Collections\Common\Configuration;
use Rekalogika\Domain\Collections\Common\Count\CountStrategy;
use Rekalogika\Domain\Collections\Common\Count\RestrictedCountStrategy;
use Rekalogika\Domain\Collections\Common\Internal\OrderByUtil;
use Rekalogika\Domain\Collections\Common\Trait\RecollectionTrait;
use Rekalogika\Domain\Collections\Common\Trait\SafeCollectionTrait;
use Rekalogika\Domain\Collections\Trait\ExtraLazyTrait;
use Rekalogika\Domain\Collections\Trait\RecollectionDxTrait;
use Rekalogika\Domain\Collections\Trait\RecollectionPageableTrait;

/**
 * @template TKey of array-key
 * @template T
 * @implements Recollection<TKey,T>
 */
class RecollectionDecorator implements Recollection
{
    /** @use RecollectionPageableTrait<TKey,T> */
    use RecollectionPageableTrait;

    /** @use SafeCollectionTrait<TKey,T> */
    use SafeCollectionTrait;

    /** @use RecollectionDxTrait<TKey,T> */
    use RecollectionDxTrait;

    /**
     * @use RecollectionTrait<TKey,T>
     * @use ExtraLazyTrait<TKey,T>
     */
    use RecollectionTrait, ExtraLazyTrait {
        ExtraLazyTrait::contains insteadof RecollectionTrait;
        ExtraLazyTrait::containsKey insteadof RecollectionTrait;
        ExtraLazyTrait::get insteadof RecollectionTrait;
        ExtraLazyTrait::slice insteadof RecollectionTrait;
        ExtraLazyTrait::offsetExists insteadof RecollectionTrait;
        ExtraLazyTrait::offsetGet insteadof RecollectionTrait;
        ExtraLazyTrait::offsetSet insteadof RecollectionTrait;
        ExtraLazyTrait::add insteadof RecollectionTrait;
    }

    /**
     * @var null|\WeakMap<object,array<string,self<array-key,mixed>>>
     */
    private static ?\WeakMap $instances = null;

    /**
     * @var Collection<TKey,T>&Selectable<TKey,T>
     */
    private readonly Collection&Selectable $collection;

    /**
     * @var non-empty-array<string,Order>
     */
    private readonly array $orderBy;

    private readonly Criteria $criteria;
    private readonly CountStrategy $count;

    /**
     * @param Collection<TKey,T> $collection
     * @param null|non-empty-array<string,Order>|string $orderBy
     * @param int<1,max> $itemsPerPage
     * @param null|int<1,max> $softLimit
     * @param null|int<1,max> $hardLimit
     */
    final private function __construct(
        Collection $collection,
        array|string|null $orderBy = null,
        private readonly ?string $indexBy = null,
        private readonly int $itemsPerPage = 50,
        ?CountStrategy $count = null,
        private readonly ?int $softLimit = null,
        private readonly ?int $hardLimit = null,
    ) {
        // handle collection

        if (!$collection instanceof Selectable) {
            throw new UnexpectedValueException('The wrapped collection must implement the Selectable interface.');
        }

        $this->collection = $collection;

        // handle orderBy

        $this->orderBy = OrderByUtil::normalizeOrderBy(
            orderBy: $orderBy,
            defaultOrderBy: $this->getDefaultOrderBy()
        );

        $this->criteria = Criteria::create()->orderBy($this->orderBy);

        // handle count strategy

        $this->count = $count ?? new RestrictedCountStrategy();
    }

    /**
     * @template STKey of array-key
     * @template ST
     * @param Collection<STKey,ST> $collection
     * @param null|non-empty-array<string,Order>|string $orderBy
     * @param int<1,max> $itemsPerPage
     * @param null|int<1,max> $softLimit
     * @param null|int<1,max> $hardLimit
     * @return static
     */
    final public static function create(
        Collection $collection,
        array|string|null $orderBy = null,
        ?string $indexBy = null,
        int $itemsPerPage = 50,
        ?CountStrategy $count = null,
        ?int $softLimit = null,
        ?int $hardLimit = null,
    ): Recollection {
        if (self::$instances === null) {
            /** @var \WeakMap<object,array<string,self<array-key,mixed>>>    */
            $weakmap = new \WeakMap();
            // @phpstan-ignore-next-line
            self::$instances = $weakmap;
        }

        $cacheKey = hash('xxh128', serialize([
            $orderBy,
            $indexBy,
            $itemsPerPage,
        ]));

        if (isset(self::$instances[$collection][$cacheKey])) {
            /** @var static */
            return self::$instances[$collection][$cacheKey];
        }

        /** @psalm-suppress UnsafeGenericInstantiation */
        $newInstance = new static(
            collection: $collection,
            orderBy: $orderBy,
            indexBy: $indexBy,
            itemsPerPage: $itemsPerPage,
            count: $count,
            softLimit: $softLimit,
            hardLimit: $hardLimit,
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
        return $this->count;
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
        return $this->orderBy;
    }

    /**
     * @return Collection<TKey,T>
     */
    private function getRealCollection(): Collection
    {
        return $this->collection;
    }

    /**
     * @return non-empty-array<string,Order>|string
     */
    protected function getDefaultOrderBy(): array|string
    {
        return Configuration::$defaultOrderBy;
    }

    /**
     * @param int<1,max> $itemsPerPage
     */
    final public function withItemsPerPage(int $itemsPerPage): static
    {
        return static::create(
            collection: $this->collection,
            orderBy: $this->orderBy,
            itemsPerPage: $itemsPerPage,
            count: $this->count,
            softLimit: $this->softLimit,
            hardLimit: $this->hardLimit,
        );
    }

    private function getUnderlyingCountable(): \Countable
    {
        return $this->collection;
    }
}
