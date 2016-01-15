<?php 

namespace CmsCanvas\Http\Controllers\Admin;

use View, Theme, Admin, Request, Input, Redirect, DB, Validator, Auth, Hash, stdClass, Session, Content, Config, Storage;
use CmsCanvas\Models\User;
use CmsCanvas\Models\Role;
use CmsCanvas\Models\Timezone;
use CmsCanvas\Http\Controllers\Admin\AdminController;

class UserController extends AdminController {

    /**
     * Display login screen
     *
     * @return View
     */
    public function getLogin()
    {
        if (Auth::check()) {
            return Redirect::route('admin.index');
        }

        $this->layout->content = View::make('cmscanvas::admin.user.login');
        $this->layout->disableNotifications = true;
    }

    /**
     * Attempt to login
     *
     * @return Redirect
     */
    public function postLogin()
    {
        $credentials = [
            'email' => Input::get('email'),
            'password' => Input::get('password'),
            'active' => 1,
        ];

        $rememberMe = (Input::get('remember_me')) ? true : false;

        if (Auth::attempt($credentials, $rememberMe)) {
            return Redirect::route('admin.index');
        }

        return Redirect::route('admin.user.login')->withInput(Input::except('password'))
            ->with('error', 'Login failed!');
    }

    /**
     * Log a user out
     *
     * @return Redirect
     */
    public function getLogout()
    {
        Auth::logout();
        Session::flush();

        return Redirect::route('admin.user.login');
    }

    /**
     * Display all users
     *
     * @return View
     */
    public function getUsers()
    {
        $content = View::make('cmscanvas::admin.user.users');

        $filter = User::getSessionFilter();
        $orderBy = User::getSessionOrderBy();

        $users = new User;
        $users = $users->with('roles')
            ->applyFilter($filter)
            ->applyOrderBy($orderBy);

        $roles = Role::all();

        $content->users = $users->paginate(50);
        $content->filter = new stdClass();
        $content->filter->filter = $filter;
        $content->orderBy = $orderBy;
        $content->roles = $roles;

        $this->layout->breadcrumbs = [Request::path() => 'Users'];
        $this->layout->content = $content;

    }

    /**
     * Saves filter and order by requests to the current user's session
     *
     * @return View
     */
    public function postUsers()
    {
        User::processFilterRequest();

        return Redirect::route('admin.user.users');
    }

    /**
     * Deletes user(s) that are posted in the selected array
     *
     * @return View
     */
    public function postDelete()
    {
        $selected = Input::get('selected');

        if (empty($selected) || ! is_array($selected)) {
            return Redirect::route('admin.user.users')
                ->with('notice', 'You must select at least one user to delete.');
        }

        $selected = array_values($selected);

        if (in_array(Auth::user()->id, $selected)) {
            return Redirect::route('admin.user.users')
                ->with('error', 'Failed to delete user(s) because you cannot delete yourself.');
        }

        User::destroy($selected);

        return Redirect::route('admin.user.users')
            ->with('message', 'The selected user(s) were sucessfully deleted.');;
    }

    /**
     * Display add user form
     *
     * @return View
     */
    public function getAdd()
    {
        // Routed to getEdit
    }

    /**
     * Create a new user
     *
     * @return View
     */
    public function postAdd()
    {
        // Routed to postEdit
    }

    /**
     * Display add user form
     *
     * @return View
     */
    public function getEdit($user = null)
    {
        Theme::addPackage('avatar_image_field');

        $roles = Role::all();
        $timezones = Timezone::all();
        
        $content = View::make('cmscanvas::admin.user.edit');
        $content->editMode = true;
        $content->roles = $roles;
        $content->timezones = $timezones;
        $content->user = $user;

        $this->layout->content = $content;
    }

    /**
     * Update an existing user
     *
     * @return View
     */
    public function postEdit($user = null)
    {
        $rules = [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => "required|email|unique:users,email".(($user == null) ? "" : ",{$user->id}"),
            'phone' => 'regex:/[0-9]{10,11}/'
        ];

        // Require password to be set for a new user
        if ($user == null || Input::get('password')) {
            $rules['password'] = 'required|confirmed|min:6';
            $rules['password_confirmation'] = 'required';
        }

        $validator = Validator::make(Input::all(), $rules);

        if ($validator->fails()) {
            if ($user == null) {
                return Redirect::route('admin.user.add')
                    ->withInput()
                    ->with('error', $validator->messages()->all());
            } else {
                return Redirect::route('admin.user.edit', $user->id)
                    ->withInput()
                    ->with('error', $validator->messages()->all());
            }
        }

        $user = ($user == null) ? new User : $user;
        $user->fill(Input::all());

        if (Input::get('password')) {
            $user->password = Hash::make(Input::get('password'));
        }

        $user->save();
        $user->roles()->sync(Input::get('user_roles', []));

        return Redirect::route('admin.user.users')
            ->with('message', "{$user->getFullName()} was successfully updated.");
    }

    /**
     * Generate a thumbnail from the specified image path
     *
     * @return string
     */
    public function postCreateAvatarThumbnail()
    {
        return Content::thumbnail(
            Input::get('image_path'), 
            ['width' => 100, 'height' => 100, 'crop' => true, 'no_image' => Theme::asset('images/portrait.jpg')]
        );
    }

    /**
     * View a users's profile
     *
     * @return View
     */
    public function getProfile($user)
    {
        $content = View::make('cmscanvas::admin.user.profile');
        $content->user = $user;

        $this->layout->content = $content;
    }

    /**
     * Edit authenticated users's profile
     *
     * @return View
     */
    public function getEditProfile()
    {
        $content = View::make('cmscanvas::admin.user.account.editProfile');
        $content->user = Auth::user();
        $content->timezones = Timezone::all();;

        $this->layout->content = $content;
    }

    /**
     * Update an authenticated user's profile
     *
     * @return View
     */
    public function postEditProfile()
    {
        $user = Auth::user();

        $rules = [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => "required|email|unique:users,email,{$user->id}",
            'phone' => 'regex:/[0-9]{10,11}/'
        ];

        $validator = Validator::make(Input::all(), $rules);

        if ($validator->fails()) {
            return Redirect::route('admin.user.account.editProfile')
                ->withInput()
                ->with('error', $validator->messages()->all());
        }

        $user->fill(Input::all());
        $user->save();

        return Redirect::route('admin.user.account.editProfile')
            ->with('message', "Profile was successfully updated.");
    }

    /**
     * Update authenticated users's avatar
     *
     * @return View
     */
    public function getUpdateAvatar()
    {
        $content = View::make('cmscanvas::admin.user.account.updateAvatar');
        $content->user = Auth::user();

        $this->layout->content = $content; 
    }

    /**
     * Update authenticated users's avatar
     *
     * @return View
     */
    public function postUpdateAvatar()
    {
        $rules = [
            'image_upload' => 'required|max:2048|mimes:jpeg,gif,png',
        ];

        $validator = Validator::make(Input::all(), $rules);

        if (empty(Input::get('remove_image')) && $validator->fails()) {
            return Redirect::route('admin.user.account.updateAvatar')
                ->withInput()
                ->with('error', $validator->messages()->all());
        }

        $user = Auth::user();

        // Delete the old avatar
        if (strpos($user->avatar, 'uploads/avatars/'.$user->id) !== false) {
            @unlink(public_path($user->avatar));
        }

        if (empty(Input::get('remove_image'))) {
            $path = trim(Config::get('cmscanvas::config.avatars'), '/');
            $extension = Input::file('image_upload')->getClientOriginalExtension();
            $fileName = $user->id.'.'.$extension;
            Input::file('image_upload')->move(public_path($path), $fileName);

            $user->avatar = $path.'/'.$fileName;
        } else {
            $user->avatar = null;
        }

        $user->save();

        return Redirect::route('admin.user.account.updateAvatar')
            ->with('message', "Avatar was successfully updated.");
    }

    /**
     * Change authenticated users's password
     *
     * @return View
     */
    public function getChangePassword()
    {
        $content = View::make('cmscanvas::admin.user.account.changePassword');

        $this->layout->content = $content; 
    }

    /**
     * Change authenticated users's password
     *
     * @return View
     */
    public function postChangePassword()
    {
        $rules = [
            'password' => 'required|confirmed|min:6',
            'password_confirmation' => 'required',
        ];

        $validator = Validator::make(Input::all(), $rules);

        if ($validator->fails()) {
            return Redirect::route('admin.user.account.changePassword')
                ->withInput()
                ->with('error', $validator->messages()->all());
        }

        $user = Auth::user();
        $user->password = Hash::make(Input::get('password'));
        $user->save();

        return Redirect::route('admin.user.account.changePassword')
            ->with('message', "Password was successfully updated.");
    }

}