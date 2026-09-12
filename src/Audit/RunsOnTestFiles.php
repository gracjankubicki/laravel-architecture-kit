<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/**
 * Marks a rule that is meant to read test files.
 *
 * Rules are written for application code and most of them never check the path: five
 * built-in rules gate only on the enabled architecture, and two match any path
 * containing `Payload`. Once test files enter the audit scope those rules would report
 * findings inside tests, so a rule has to ask for them explicitly. Project rules get the
 * same safe default without changing the AuditRule contract they implement.
 */
interface RunsOnTestFiles {}
