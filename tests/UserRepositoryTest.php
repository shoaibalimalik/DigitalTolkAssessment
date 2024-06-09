<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserMeta;
use App\Models\Company;
use App\Models\Department;
use App\Models\UsersBlacklist;
use App\Models\UserLanguages;
use App\Models\Town;
use App\Models\UserTowns;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Mockery;

class UserRepositoryTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userRepository = new UserRepository();
    }

    public function testcreateOrUpdate()
    {
        $userName = $this->faker->userName;

        $request = $this->mockRequest([
            'name' => 'Dummy Name',
            'role' => 1, // let's say customerRoleId id
            'name' => $this->faker->name,
            'company_id' => '',
            'department_id' => '',
            'email' => $this->faker->unique()->safeEmail,
            'dob_or_orgid' => $this->faker->date(),
            'phone' => $this->faker->phoneNumber,
            'mobile' => $this->faker->phoneNumber,
            'password' => 'password',
            'consumer_type' => 'paid',
            'customer_type' => 'individual',
            'username' => $userName,
            'post_code' => $this->faker->postcode,
            'address' => $this->faker->address,
            'city' => $this->faker->city,
            'town' => $this->faker->city,
            'country' => $this->faker->country,
            'reference' => 'yes',
            'additional_info' => $this->faker->paragraph,
            'cost_place' => $this->faker->word,
            'fee' => $this->faker->randomFloat(2, 1, 100),
            'time_to_charge' => $this->faker->randomNumber(2),
            'time_to_pay' => $this->faker->randomNumber(2),
            'charge_ob' => $this->faker->word,
            'customer_id' => $this->faker->randomNumber(3),
            'charge_km' => $this->faker->randomNumber(2),
            'maximum_km' => $this->faker->randomNumber(2),
            'translator_type' => 'professional',
            'worked_for' => 'no',
            'gender' => 'male',
            'translator_level' => 'high',
            'status' => '1',
            'new_towns' => 'NewTown',
            'user_towns_projects' => [],
        ]);

        $user = $this->userRepository->createOrUpdate(null, $request);

        $company = Company::where(['name' => 'Dummy Name'])->first(); 

        $this->assertInstanceOf(User::class, $user);

        $this->assertInstanceOf(Company::class, $company);

        $this->assertDatabaseHas('users', ['email' => $request['email']]);

        $this->assertDatabaseHas('users_meta', ['username' => $userName]);
    }

    private function mockRequest(array $attributes)
    {
        $request = Mockery::mock('Illuminate\Http\Request');
        $request->shouldReceive('all')->andReturn($attributes);
        foreach ($attributes as $key => $value) {
            $request->shouldReceive('input')->with($key)->andReturn($value);
        }
        return $request;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
