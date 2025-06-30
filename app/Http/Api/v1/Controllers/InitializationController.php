<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InitializationController extends Controller
{

    public function checkInitialization(Request $request): JsonResponse {
        if($this->isInitialized()){
            return response()->json(
                [
                    "message" => "Sistema já inicializado",
                ]);
        }else{
            return response()->json(
                [
                    "message" => "Sistema já inicializado",
                ],
            404);
        }
    }

    protected function isInitialized(): bool {
        $users_count = LandlordUser::all()->count();
        $tenants_count = Tenant::all()->count();

        return $users_count > 0 || $tenants_count > 0;
    }

    public function initialize(InitializeRequest $request): JsonResponse {

        if($this->isInitialized()){
            return response()->json(
                [
                    "success" => false,
                    "message" => "Sistema já inicializado",
                    "errors" => [
                        "user" => ["Sistema já inicializado"]
                    ]],
                403);
        }


        DB::connection('landlord')->beginTransaction();
        try{
            $new_tenant = Tenant::create([
                "name" => $request->tenant["name"],
                "subdomain" => $request->tenant["subdomain"]
            ]);

            $new_tenant->addDomains($request->tenant["domains"]);

            $new_tenant->makeCurrent();
            $admin_role = LandlordRole::create([
                ...$request->validated()['role']
            ]);

            $admin_tenant_template = $new_tenant->roleTemplates()->create(
                [
                    "name" => "Admin",
                    'description' => 'Administrador',
                    "permissions" => ["*"]
                ]
            );

            $new_user = LandlordUser::create([
                "name" => $request->user['name'],
                "emails" => $request->user['emails'],
                "password" => $request->user['password']
            ]);

            $admin_role->users()->save($new_user);

            $new_user->tenantRoles()->create([
                ...$admin_tenant_template->attributesToArray(),
                'tenant_id' => $new_tenant->id,
            ]);

            foreach($request->user['emails'] as $email){
                $new_user->emails = [$email];
            }

            $token = $new_user->createToken("Initialization Token")->plainTextToken;

            $new_tenant->forgetCurrent();

            DB::connection('landlord')->commit();

        }catch (\Exception $e){
            DB::connection('landlord')->rollBack();
            throw $e;
        }

        return response()->json([
            "data" => [
                "user" => $new_user->toArray(),
                "tenant" => [
                    ...$new_tenant->attributesToArray(),
                    "role_admin_id" => $admin_tenant_template->id,
                ],
                "role" => $admin_role->toArray(),
                "token" => $token
            ]
        ], 201);

    }
}
