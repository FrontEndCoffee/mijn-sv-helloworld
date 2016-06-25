<?php

namespace App\Http\Controllers;

use App\User;
use App\UserCategory;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Laracasts\Flash\Flash;
use Validator;
use Auth;
use UserVerification;

class UserController extends Controller
{
    use ResetsPasswords;

    /**
     * Use the password broker settings for new users.
     *
     * @var string
     */
    protected $broker = 'new_users';

    /**
     * Where to redirect users after login / registration.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * The password set view.
     *
     * @var string
     */
    protected $resetView = 'account.password.set';

    /**
     * Set the email subject.
     *
     * @var string
     */
    protected $subject = 'Uw registratie link';

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $users = User::paginate(15);

        return view('user.index', compact('users'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $user_categories = UserCategory::all();

        $user_categories_values = [];
        foreach ($user_categories as $user_category) {
            $user_categories_values[$user_category->alias] = $user_category->title;
        }

        return view('user.create', compact('user_categories_values'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $request->merge([
            'user_category' => $request->get('user_category') ? $request->get('user_category') : null,
        ]);

        $this->validate($request, [
            'first_name' => 'required|regex:/^[a-zàâçéèêëîïôûùüÿñæœ\s-]+$/i|max:255',
            'name_prefix' => 'regex:/^[a-zàâçéèêëîïôûùüÿñæœ\s-]+$/i|max:16',
            'last_name' => 'required|regex:/^[a-zàâçéèêëîïôûùüÿñæœ\s-]+$/i|max:255',
            'email' => 'required|email|regex:/^[a-zA-Z0-9._%+-]+@hz.nl$/|max:255|unique:users',
            'phone_number' => ['regex:/(^\+[0-9]{2}|^\+[0-9]{2}\(0\)|^\(\+[0-9]{2}\)\(0\)|^00[0-9]{2}|^0)([0-9]{9}$|[0-9\-\s]{10}$)/'],
            'address' => ['required', 'regex:/^([1-9][e][\s])*([a-zA-Z]+(([\.][\s])|([\s]))?)+[1-9][0-9]*(([-][1-9][0-9]*)|([\s]?[a-zA-Z]+))?$/i', 'max:255'],
            'zip_code' => ['required', 'regex:/^[1-9][0-9]{3} ?(?!sa|sd|ss)[a-z]{2}$/i', 'max:7'],
            'city' => ['required', 'regex:/^([a-zA-Z\x{0080}-\x{024F}]+(?:. |-| |\'))*[a-zA-Z\x{0080}-\x{024F}]*$/u', 'max:255'],
            'account_type' => 'required',
            'activated' => 'required|boolean',
        ]);

        User::create($request->all());

        // Send password reset link to the user
        $this->sendResetLinkEmail($request);

        Flash::success('Gebruiker toegevoegd!');

        return redirect(route('user.index'));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     *
     * @return Response
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        return view('user.show', compact('user'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     *
     * @return Response
     */
    public function edit($id, Request $request)
    {
        $user = User::findOrFail($id);
        $user_categories = UserCategory::all();

        $user_categories_values = [];
        foreach ($user_categories as $user_category) {
            $user_categories_values[$user_category->alias] = $user_category->title;
        }

        return view('user.edit', compact('user', 'user_categories_values'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     *
     * @return Response
     */
    public function update($id, Request $request)
    {
        $user = Auth::user();

        $request->merge([
            'user_category' => $request->get('user_category') ? $request->get('user_category') : null,
        ]);

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|regex:/^[a-zàâçéèêëîïôûùüÿñæœ\s-]+$/i|max:255',
            'name_prefix' => 'regex:/^[a-zàâçéèêëîïôûùüÿñæœ\s-]+$/i|max:16',
            'last_name' => 'required|regex:/^[a-zàâçéèêëîïôûùüÿñæœ\s-]+$/i|max:255',
            'email' => 'required|email|regex:/^[a-zA-Z0-9._%+-]+@hz.nl$/|max:255',
            'phone_number' => ['regex:/(^\+[0-9]{2}|^\+[0-9]{2}\(0\)|^\(\+[0-9]{2}\)\(0\)|^00[0-9]{2}|^0)([0-9]{9}$|[0-9\-\s]{10}$)/'],
            'address' => ['required', 'regex:/^([1-9][e][\s])*([a-zA-Z]+(([\.][\s])|([\s]))?)+[1-9][0-9]*(([-][1-9][0-9]*)|([\s]?[a-zA-Z]+))?$/i', 'max:255'],
            'zip_code' => ['required', 'regex:/^[1-9][0-9]{3} ?(?!sa|sd|ss)[a-z]{2}$/i', 'max:7'],
            'city' => ['required', 'regex:/^([a-zA-Z\x{0080}-\x{024F}]+(?:. |-| |\'))*[a-zA-Z\x{0080}-\x{024F}]*$/u', 'max:255'],
            'account_type' => 'required',
            'activated' => 'required|boolean',
        ]);

        // Extra checks if the user edits his own account
        if ($id == $user->id) {
            $validator->after(function ($validator) use ($request, $user) {
                // Check if the user wants to deactivate his own account
                if (! $request->get('activated')) {
                    $validator->errors()->add('activated', 'het is niet toegestaan jezelf te deactiveren.');
                    $request->merge(['activated' => $user->activated]);
                }

                // Check if the user wants to change his own role
                if ($request->get('account_type') != $user->account_type) {
                    $validator->errors()->add('account_type', 'het is niet toegestaan om je eigen account type te wijzigen.');
                    $request->merge(['account_type' => $user->account_type]);
                }
            });
        }

        if ($validator->fails()) {
            return redirect(route('user.edit', $id))
                ->withErrors($validator)
                ->withInput();
        }

        $user = User::findOrFail($id);
        $user_old_email = $user->email;
        $user->update($request->all());

        // Send email verification link when the email address has been changed
        if ($user_old_email != $request->get('email')) {
            UserVerification::generate($user);
            UserVerification::send($user, 'Verifieer je e-mailadres');
        }

        Flash::success('Gebruiker bijgewerkt.');

        return redirect(route('user.show', $id));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     *
     * @param Request $request
     * @return Response
     */
    public function destroy($id, Request $request)
    {
        $user = $request->user();

        // Check if the user wants to delete his own account
        if ($id == $user->id) {
            Flash::error('Het is niet toegestaan jezelf te verwijderen.');

            return redirect(route('user.index'));
        }

        User::destroy($id);

        Flash::success('Gebruiker verwijderd!');

        return redirect(route('user.index'));
    }

    /**
     * Activate or deactivate the given user.
     *
     * @param  int  $id
     *
     * @return Response
     */
    public function activate($id, Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'activated' => 'boolean',
        ]);

        // Validate current password
        $validator->after(function ($validator) use ($id, $user) {
            if ($id == $user->id) {
                $validator->errors()->add('activated', 'het is niet toegestaan jezelf te activeren of deactiveren.');
            }
        });

        if ($validator->fails()) {
            return redirect('user')
                ->withErrors($validator)
                ->withInput();
        }

        $user = User::findOrFail($id);
        $user->update($request->only('activated'));

        $activated = $request->get('activated');
        if ($activated) {
            Flash::success('Gebruiker geactiveerd.');
        } else {
            Flash::success('Gebruiker gedeactiveerd.');
        }

        return redirect(route('user.index'));
    }
}
