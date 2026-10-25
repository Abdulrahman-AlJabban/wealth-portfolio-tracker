<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddMember;
use App\Actions\ChangeMemberRole;
use App\Actions\RemoveMember;
use App\Enums\Role;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Portfolio;
use App\Models\PortfolioMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Thin: the member Actions authorize "manage" and enforce "at least one owner".
 * Scoped binding guarantees {member} belongs to {portfolio}.
 */
class MemberController extends Controller
{
    public function store(StoreMemberRequest $request, Portfolio $portfolio, AddMember $addMember): RedirectResponse
    {
        $addMember->execute(
            $request->user(),
            $portfolio,
            (int) $request->validated('user_id'),
            Role::from($request->validated('role')),
        );

        return back()->with('success', 'Member added.');
    }

    public function update(UpdateMemberRequest $request, Portfolio $portfolio, PortfolioMember $member, ChangeMemberRole $changeMemberRole): RedirectResponse
    {
        $changeMemberRole->execute($request->user(), $portfolio, $member, Role::from($request->validated('role')));

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, Portfolio $portfolio, PortfolioMember $member, RemoveMember $removeMember): RedirectResponse
    {
        $removeMember->execute($request->user(), $portfolio, $member);

        return back()->with('success', 'Member removed.');
    }
}
