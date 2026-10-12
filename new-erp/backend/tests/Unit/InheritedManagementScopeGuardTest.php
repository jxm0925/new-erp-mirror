<?php

namespace Tests\Unit;

use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Services\Erp\InheritedManagementScopeGuard;
use App\Services\Erp\ItemManagementScopeService;
use App\Services\Erp\PurchaseExchangeApplicationService;
use App\Services\Erp\PurchaseReturnApplicationService;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** No Laravel application or PDO: any attempted model connection fails immediately. */
final class InheritedManagementScopeGuardTest extends TestCase
{
    private $priorFacadeApplication;
    private $priorConnectionResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorFacadeApplication = Facade::getFacadeApplication();
        $this->priorConnectionResolver = Model::getConnectionResolver();
        $container = new Container;
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
        Facade::clearResolvedInstance('validator');
        Facade::setFacadeApplication($container);
        Model::setConnectionResolver(new class implements ConnectionResolverInterface {
            public function connection($name = null) { throw new \LogicException('Database access is forbidden in this unit test.'); }
            public function getDefaultConnection() { return 'forbidden'; }
            public function setDefaultConnection($name) { throw new \LogicException('Database access is forbidden in this unit test.'); }
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('validator');
        Facade::setFacadeApplication($this->priorFacadeApplication);
        if ($this->priorConnectionResolver) Model::setConnectionResolver($this->priorConnectionResolver);
        else Model::unsetConnectionResolver();
        parent::tearDown();
    }

    #[DataProvider('allowedSourceScopes')]
    public function test_historical_source_scope_is_preserved_without_defaulting(?string $scope): void
    {
        self::assertSame($scope, InheritedManagementScopeGuard::nullable($scope));
    }

    public static function allowedSourceScopes(): array
    {
        return [[null], ['factory'], ['office']];
    }

    #[DataProvider('invalidSourceScopes')]
    public function test_invalid_non_null_source_values_are_rejected(mixed $scope): void
    {
        try {
            InheritedManagementScopeGuard::nullable($scope);
            self::fail('Invalid inherited scope was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('management_scope', $exception->errors());
        }
    }

    public static function invalidSourceScopes(): array
    {
        return [[''], ['Factory'], [' office'], ['office '], ['unknown'], [0], [false], [[]]];
    }

    #[DataProvider('invalidStoredScopes')]
    public function test_item_update_rejects_inherited_invalid_scope_before_any_query(string $scope): void
    {
        $item = new Item(['management_scope' => $scope, 'item_type' => 'service']);
        $this->expectException(ValidationException::class);
        (new ItemManagementScopeService)->prepareItem([], $item);
    }

    #[DataProvider('invalidStoredScopes')]
    public function test_category_update_rejects_inherited_invalid_scope_before_any_query(string $scope): void
    {
        $category = new ItemCategory(['management_scope' => $scope]);
        $this->expectException(ValidationException::class);
        (new ItemManagementScopeService)->prepareCategory([], $category);
    }

    public static function invalidStoredScopes(): array
    {
        return [[''], ['Factory'], ['unknown'], ['office ']];
    }

    #[DataProvider('validItemScopes')]
    public function test_valid_item_scope_and_legacy_null_fallback_are_unchanged(?string $stored, string $type, string $expected): void
    {
        $item = new Item(['management_scope' => $stored, 'item_type' => $type]);
        $result = (new ItemManagementScopeService)->prepareItem([], $item);
        self::assertSame($expected, $result['management_scope']);
    }

    public static function validItemScopes(): array
    {
        return [['factory', 'service', 'factory'], ['office', 'service', 'office'],
            [null, 'service', 'factory'], [null, 'office_consumable', 'office']];
    }

    #[DataProvider('allowedSourceScopes')]
    public function test_category_valid_values_and_legacy_null_fallback_are_unchanged(?string $scope): void
    {
        $category = new ItemCategory(['management_scope' => $scope]);
        $result = (new ItemManagementScopeService)->prepareCategory([], $category);
        self::assertSame($scope ?? 'factory', $result['management_scope']);
    }

    #[DataProvider('creationMethods')]
    public function test_each_return_and_exchange_creation_checks_source_before_new_document_write(string $class, string $method, string $model): void
    {
        // Static wiring contract supplements value tests; it does not claim transactional integration coverage.
        $reflection = new ReflectionMethod($class, $method);
        $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1));
        $guard = strpos($source, '$managementScope = InheritedManagementScopeGuard::nullable(');
        $write = strpos($source, $model.'::create(');
        self::assertNotFalse($guard);
        self::assertNotFalse($write);
        self::assertLessThan($write, $guard);
        self::assertStringContainsString("'management_scope' => \$managementScope,", $source);
    }

    public static function creationMethods(): array
    {
        return [
            [PurchaseReturnApplicationService::class, 'create', 'PurchaseReturn'],
            [PurchaseReturnApplicationService::class, 'createRejectedFromDefect', 'PurchaseReturn'],
            [PurchaseReturnApplicationService::class, 'createFromInventoryQuality', 'PurchaseReturn'],
            [PurchaseExchangeApplicationService::class, 'createFromInventoryQuality', 'PurchaseExchangeOrder'],
            [PurchaseExchangeApplicationService::class, 'createFromDefect', 'PurchaseExchangeOrder'],
        ];
    }
}
