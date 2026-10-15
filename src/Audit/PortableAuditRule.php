<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/** Declares that a rule uses shared syntax and does not require a PHP parser AST. */
interface PortableAuditRule extends AuditRule {}
