<?php

declare(strict_types=1);

namespace FChubMemberships\Support;

final class PreparedCustomTableQuery
{
    public function __construct(
        private readonly string $sql,
        object $issuanceCapability,
    )
    {
        if (!CustomTableDatabase::acceptsQueryIssuanceCapability($issuanceCapability)) {
            throw new \LogicException(
                'Prepared custom-table queries can only be issued by CustomTableDatabase.',
            );
        }
    }

    public function sql(): string
    {
        return $this->sql;
    }
}
