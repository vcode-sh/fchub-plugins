<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Package\AssetManifestEntry;
use CartShift\Domain\Transfer\StageContext;

defined('ABSPATH') || exit;

interface TargetAssetStager
{
    public function stage(AssetManifestEntry $asset, StageContext $context): StagedAsset;

    public function verify(StagedAsset $asset): void;

    public function rollback(StagedAsset $asset): void;
}
