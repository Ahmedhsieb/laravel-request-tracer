<?php

declare(strict_types=1);

use RequestTracer\Tests\LocalTestCase;
use RequestTracer\Tests\OrderAppTestCase;
use RequestTracer\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(OrderAppTestCase::class)->in('Order');
uses(LocalTestCase::class)->in('Local');
