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
use Rekalogika\Contracts\Rekapager\PageableInterface;
use Rekalogika\Domain\Collections\Exception\UnexpectedValueException;
use Rekalogika\Domain\Collections\Trait\CollectionTrait;
use Rekalogika\Domain\Collections\Trait\RecollectionTrait;

/**
 * @template TKey of array-key
 * @template T
 * @implements PageableInterface<TKey,T>
 * @implements Collection<TKey,T>
 */
class Recollection implements PageableInterface, Collection
{
    /** @use RecollectionTrait<TKey,T> */
    use RecollectionTrait;

    /** @use CollectionTrait<TKey,T> */
    use CollectionTrait;

    /**
     * @var Collection<TKey,T>&Selectable<TKey,T>
     */
    private readonly Collection&Selectable $collection;

    /**
     * @var array<string,Order>
     */
    private readonly array $orderBy;

    private readonly Criteria $criteria;

    /**
     * @param Collection<TKey,T> $collection
     * @param null|array<string,Order>|string $orderBy
     * @param int<1,max> $itemsPerPage
     * @param null|int<0,max> $count
     * @param null|int<1,max> $softLimit
     * @param null|int<1,max> $hardLimit
     */
    public function __construct(
        Collection $collection,
        array|string|null $orderBy = null,
        private readonly int $itemsPerPage = 50,
        private readonly CountStrategy $countStrategy = CountStrategy::Restrict,
        private ?int &$count = null,
        private readonly ?int $softLimit = null,
        private readonly ?int $hardLimit = null,
        private readonly ?bool $strict = null,
    ) {
        if (!$collection instanceof Selectable) {
            throw new UnexpectedValueException('The wrapped collection must implement the Selectable interface.');
        }

        $this->collection = $collection;

        if ($orderBy === null) {
            $orderBy = RecollectionConfiguration::$defaultOrderBy;
        } elseif (\is_string($orderBy)) {
            $orderBy = [$orderBy => Order::Ascending];
        }

        $this->orderBy = $orderBy;

        $this->criteria = Criteria::create()->orderBy($this->orderBy);
    }

    /**
     * @param null|Collection<TKey,T> $collection
     * @param null|array<string,Order>|string $orderBy
     * @param null|int<1,max> $itemsPerPage
     * @param null|int<0,max> $count
     * @param null|int<1,max> $softLimit
     * @param null|int<1,max> $hardLimit
     */
    protected function createFrom(
        ?Collection $collection = null,
        array|string|null $orderBy = null,
        ?int $itemsPerPage = 50,
        ?CountStrategy $countStrategy = CountStrategy::Restrict,
        ?int &$count = null,
        ?int $softLimit = null,
        ?int $hardLimit = null,
        ?bool $strict = null,
    ): static {
        $count = $count ?? $this->count;

        // @phpstan-ignore-next-line
        return new static(
            collection: $collection ?? $this->collection,
            orderBy: $orderBy ?? $this->orderBy,
            itemsPerPage: $itemsPerPage ?? $this->itemsPerPage,
            countStrategy: $countStrategy ?? $this->countStrategy,
            count: $count,
            softLimit: $softLimit ?? $this->softLimit,
            hardLimit: $hardLimit ?? $this->hardLimit,
            strict: $strict ?? $this->strict,
        );
    }

    /**
     * @param null|int<0,max> $count
     * @return CriteriaRecollection<TKey,T>
     */
    protected function withCriteria(
        Criteria $criteria,
        CountStrategy $countStrategy = CountStrategy::Restrict,
        ?int &$count = null,
    ): CriteriaRecollection {
        // if $criteria has no orderings, add the current ordering
        if (\count($criteria->orderings()) === 0) {
            $criteria = $criteria->orderBy($this->orderBy);
        }

        return new CriteriaRecollection(
            collection: $this->collection,
            criteria: $criteria,
            itemsPerPage: $this->itemsPerPage,
            countStrategy: $countStrategy,
            count: $count,
            softLimit: $this->softLimit,
            hardLimit: $this->hardLimit,
            strict: $this->strict,
        );
    }
}
