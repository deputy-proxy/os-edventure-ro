<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Tables\Table;
use Hexters\HexaLite\Models\HexaRole;
use Hexters\HexaLite\Resources\Roles\Widgets\RoleUsersWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleUsersWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_users_widget_provides_an_eloquent_builder_for_the_role_users_relation(): void
    {
        $role = HexaRole::query()->create([
            'name' => 'Assistant',
            'created_by_name' => 'Test',
            'access' => [],
            'guard' => hexa()->guard(),
        ]);
        $user = User::factory()->create();
        $role->users()->attach($user);

        $widget = new RoleUsersWidget;
        $widget->record = $role;

        $query = $widget->table(Table::make($widget))->getQuery();

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertSame([$user->getKey()], $query->pluck('users.id')->all());
    }

    public function test_role_users_widget_returns_an_empty_builder_without_a_role_record(): void
    {
        $widget = new RoleUsersWidget;

        $query = $widget->table(Table::make($widget))->getQuery();

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertSame([], $query->get()->all());
    }
}
