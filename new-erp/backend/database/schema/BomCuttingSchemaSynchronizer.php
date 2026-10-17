<?php

declare(strict_types=1);

namespace Database\Schema;

require_once __DIR__.'/ProductionOrderSchemaSynchronizer.php';

/** Explicit BOM-only entry; the ordinary initial migration helper remains strict. */
final class BomCuttingSchemaSynchronizer extends ProductionOrderSchemaSynchronizer
{
    public function __construct() { parent::__construct('erp_bom_items'); }
}
