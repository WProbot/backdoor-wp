<?php

namespace Jmau\BackdoorWp;
/**
 * This is a fork from https://github.com/BoiteAWeb/SecuPress-Backdoor-User
 * I prefer using select2 cause some installations may have a lot of users and a lot of custom roles
 * @author Julien Maury
 */

use WP_Roles;
use WP_Error;
use WP_User;

class BackdoorWP {

	private $plugins;
	private $roles;
	private $muPlugins;
	private $noChangeMessage;
	private $message;

	const VERSION = '1.0';

	public function __construct() {
		$this->plugins         = [];
		$this->muPlugins       = [];
		$this->roles           = [];
		$this->message         = '';
		$this->noChangeMessage = 'No change so no update but that is not an error !';
	}

	public function maybeLoadWPDependencies(): BackdoorWP {

		define( 'DONOTCACHEPAGE', true );

		while ( ! is_file( 'wp-load.php' ) ) {
			if ( is_dir( '..' ) && getcwd() != '/' ) {
				chdir( '..' );
			} else {
				die( 'Could not find WordPress!' );
			}
		}

		require_once 'wp-load.php';
		if ( ! class_exists( 'WP_Roles' ) ) {
			require_once './wp-admin/includes/user.php';
		}

		return $this;
	}

	public function setWPRoles(): BackdoorWP {
		$roles = new WP_Roles();
		$roles = $roles->get_names();
		$roles = array_map( 'translate_user_role', $roles );

		if ( is_multisite() ) {
			$roles = array_merge( [ 'site_admin' => 'Super Admin' ], $roles );
		}

		$this->roles = $roles;

		return $this;
	}

	public function setPluginList( array $value = [] ): BackdoorWP {

		if ( empty( $value ) ) {
			$value = get_option( 'active_plugins', [] );
		}

		$this->plugins = array_filter( $value );

		return $this;
	}

	public function setMuPluginList( array $value = [] ): BackdoorWP {

		if ( empty( $value ) ) {
			$value = wp_get_mu_plugins();
		}

		$this->muPlugins = array_filter( $value );

		return $this;
	}

	public function getWPRoles(): array {
		return $this->roles;
	}

	public function getPluginList(): array {
		return $this->plugins;
	}

	public function getMuPluginList(): array {
		return $this->muPlugins;
	}

	public function getAllUsers(): array {

		global $wpdb;
		$allUsers = [];
		if ( function_exists( 'get_users' ) ) {
			$allUsers = get_users();
		} else {
			$usersID = $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->users . ' ORDER BY ID ASC' );
			foreach ( $usersID as $uid ) {
				$allUsers[] = get_userdata( $uid );
			}
		}

		return $allUsers;
	}

	public function getUserData(): array {

		$array = [
			'new_user_email' => ! empty( $_REQUEST['user_email'] ) && str_replace( ' ', '+', $_REQUEST['user_email'] != '' ? $_REQUEST['user_email'] : time() . '@fake' . time() . '.com' ),
			'new_user_pass'  => ! empty( $_REQUEST['user_pass'] ) ? $_REQUEST['user_pass'] : time(),
			'new_user_role'  => ! empty( $_REQUEST['user_role'] ) && array_key_exists( $_REQUEST['user_role'], $this->roles ) ? $_REQUEST['user_role'] : 'administrator',
		];

		$array['new_user_login'] = ! empty( $_REQUEST['user_login'] ) ? $_REQUEST['user_login'] : $array['new_user_role'] . '_' . substr( md5( uniqid() . time() ), 0, 7 );

		return $array;
	}

	private function isAction( string $action ): bool {
		return isset( $_REQUEST['action'] ) && $action === $_REQUEST['action'];
	}

	private function createUser() {

		if ( ! $this->isAction( 'create_user' ) ) {
			return false;
		}

		$user = $this->getUserData();

		if ( username_exists( $user['new_user_login'] ) ) {
			wp_die( new WP_Error( 'existing_user_login', 'This username is already registered.' ) );
		}

		$userId = wp_create_user( $user['new_user_login'], $user['new_user_pass'], $user['new_user_email'] );

		if ( is_wp_error( $userId ) ) {
			wp_die( new WP_Error( 'registerfail', sprintf( '<strong>ERROR</strong>: Couldn&#8217;t register you... please contact the <a href="mailto:%s">webmaster</a> !' ), esc_attr( get_option( 'admin_email' ) ) ) );
		}
		$_user = new WP_User( $userId );

		if ( is_multisite() && 'site_admin' === $user['new_user_role'] ) {
			grant_super_admin( $userId );
			$_user->set_role( 'administrator' );
		} else {
			$_user->set_role( $user['new_user_role'] );
		}

		if ( isset( $_REQUEST['log_in'] ) ) {
			wp_signon( [
				'user_login'    => $user['new_user_login'],
				'user_password' => $user['new_user_pass']
			] );

			wp_redirect( admin_url( 'profile.php' ) );
			exit;
		}
		$this->message = 'User created!';
	}

	public function maybeLoadProcessPluginsDependencies(): BackdoorWP {
		require_once ABSPATH . 'wp-includes/formatting.php';

		return $this;
	}

	private function processPlugins() {

		if ( ! $this->isAction( 'plugins' ) ) {
			return false;
		}

		$deactivate = [];

		// plugins
		if ( ( isset( $_POST['deactivate'] ) && is_array( $_POST['deactivate'] ) ) || isset( $_POST['deactivate_all'] ) ) {
			if ( ! isset( $_POST['deactivate_all'] ) ) {
				foreach ( $_POST['deactivate'] as $des ) {
					if ( intval( $des ) === intval( $des ) && isset( $this->plugins[ $des ] ) ):
						$deactivate[] = $this->plugins[ $des ];
						unset( $this->plugins[ $des ] );
					endif;
				}
				$this->plugins = array_values( $this->plugins );
				update_option( 'active_plugins', (array) $this->plugins );
			} else {
				update_option( 'active_plugins', [] );
				$this->plugins = [];
			}
			$this->message = 'Plugin(s) deactivated!';
		}

		// mu-plugins
		if ( ( isset( $_POST['delete'] ) && is_array( $_POST['delete'] ) ) || isset( $_POST['delete_all'] ) ) {
			if ( ! isset( $_POST['delete_all'] ) ) {
				foreach ( $_POST['delete'] as $del ) {
					if ( (int) $del == $del && isset( $this->muPlugins[ $del ] ) ):
						$delete[] = $this->muPlugins[ $del ];
						@unlink( $this->muPlugins[ $del ] );
						unset( $this->muPlugins[ $del ] );
					endif;
				}
				$this->muPlugins = array_values( $this->muPlugins );
			} else {
				foreach ( $this->muPlugins as $mup ) {
					@unlink( $mup );
				}
				$this->muPlugins = [];
			}
			$this->message = 'Must-Use plugins deleted!';
		}
	}

	private function editUser() {

		if ( ! $this->isAction( 'edit_user' ) ) {
			return false;
		}

		if ( $_REQUEST['user_role'] != '-1' ) {

			$user = new WP_User( $_REQUEST['user_ID'] );

			if ( is_multisite() && 'site_admin' === $user['new_user_role'] ) {
				grant_super_admin( $user->ID );
				$user->set_role( 'administrator' );
			} else {
				$user->set_role( $this->getUserData()['new_user_role'] );
			}

			$this->message = 'User updated!';
		} else {
			$this->message = $this->noChangeMessage;
		}

		// If a pass change is needed
		if ( $_REQUEST['user_pass'] != '' ) {
			// update the member's pass
			wp_update_user( [
				'ID'        => $_REQUEST['user_ID'],
				'user_pass' => $_REQUEST['user_pass']
			] );
			$this->message = 'User updated!';
		} else {
			$this->message = $this->noChangeMessage;
		}

	}

	private function login() {

		if ( ! $this->isAction( 'login_user' ) ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			wp_redirect( admin_url( 'index.php' ) );
			exit;
		}

		$user_data = get_userdata( $_REQUEST['user_ID'] );

		wp_set_current_user( $user_data->ID, $user_data->user_login );
		wp_set_auth_cookie( $user_data->ID );
		do_action( 'wp_login', $user_data->user_login, $user_data );

		wp_redirect( admin_url( 'index.php' ) );
		exit;
	}

	private function deleteUser() {

		if ( ! $this->isAction( 'delete_user' ) ) {
			return false;
		}

		$delete = wp_delete_user( $_REQUEST['user_ID'], $_REQUEST['new_user_ID'] );
		if ( ! $delete ) {
			return false;
		}

		$this->message = 'User deleted!';
	}

	private function deleteFile() {
	    if ( ! isset( $_GET['delete_file'] ) || 1 !== intval( $_GET['delete_file'] ) ) {
	        return false;
        }
		chmod( __FILE__, '0777' );
		unlink( __FILE__ );
    }

	public function processForms(): BackdoorWP {
		$this->editUser();
		$this->createUser();
		$this->deleteUser();
		$this->login();
		$this->processPlugins();
		$this->deleteFile();

		return $this;
	}

	private function displayStyles() {
		?>
        <style>
            a, abbr, acronym, address, applet, article, aside, audio, b, big, blockquote, body, canvas, caption, center, cite, code, dd, del, details, dfn, div, dl, dt, em, embed, fieldset, figcaption, figure, footer, form, h1, h2, h3, h4, h5, h6, header, hgroup, html, i, iframe, img, ins, kbd, label, legend, li, mark, menu, nav, object, ol, output, p, pre, q, ruby, s, samp, section, small, span, strike, strong, sub, summary, sup, table, tbody, td, tfoot, th, thead, time, tr, tt, u, ul, var, video {
                margin: 0;
                padding: 0;
                border: 0;
                font-size: 100%;
                font: inherit;
                vertical-align: baseline
            }

            article, aside, details, figcaption, figure, footer, header, hgroup, menu, nav, section {
                display: block
            }

            body {
                line-height: 1
            }

            ol, ul {
                list-style: none
            }

            blockquote, q {
                quotes: none
            }

            blockquote:after, blockquote:before, q:after, q:before {
                content: '';
                content: none
            }

            table {
                border-collapse: collapse;
                border-spacing: 0
            }

            small {
                font-size: small
            }

            body {
                background: #c7372a;
                font-family: Georgia, Times, "Times New Roman", serif
            }

            .wrap {
                display: grid;
                text-shadow: 0 1px 3px rgba(0, 0, 0, .5);
                margin: 3rem auto;
                max-width: 90%;
                grid-gap: 10px
            }

            h1 {
                text-align: center;
                margin: 0 0 1rem;
                color: #fafafa;
                font-size: 3rem;
                text-transform: uppercase;
                font-style: normal;
                font-variant: normal;
                font-weight: 900;
                line-height: 2rem;
                text-shadow: 0 1px 3px rgba(0, 0, 0, .5)
            }

            p.description {
                text-align: center
            }

            .form-section {
                background-color: #bc3428;
                color: #fff;
                border-radius: 5px;
                padding: 2.5rem;
                font-size: 111%;
                line-height: 150%
            }

            .alert {
                color: white;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                z-index: 73;
                padding: .75rem 1.25rem 1.25rem 6rem;
                margin-bottom: 1rem;
                border: 1px solid transparent;
                border-radius: .25rem
            }

            .alert-success {
                background-color: #dff0d8;
                border-color: #d0e9c6;
                color: #3c763d
            }

            .form-section p {
                margin: .75rem 0
            }

            p.description {
                color: #fff;
                margin: 1rem auto
            }

            p.description a, p.description a:focus, p.description a:hover, p.description a:visited {
                font-style: italic;
                color: #fff
            }

            h2 {
                font-size: 1.8rem;
                font-weight: 700;
                margin-bottom: 3rem;
                text-shadow: 0 1px 3px rgba(0, 0, 0, .5)
            }

            .s1 {
                grid-area: 1/2/2/3;
                position: relative;
                top: 50%
            }

            .s2 {
                grid-area: 2/2/3/3
            }

            .s3 {
                grid-area: 2/3/3/4
            }

            .s4 {
                grid-area: 1/1/2/2
            }

            .s5 {
                grid-area: 2/1/3/2
            }

            .s6 {
                grid-area: 1/3/2/4
            }

            .select-control {
                width: 180px;
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none
            }

            .select2-selection__rendered {
                text-shadow: none
            }

            .select2-container--open .select2-dropdown--above, .select2-container--open .select2-dropdown--below {
                background: #bc3428;
                color: #fff
            }

            .select2-container--classic .select2-search--dropdown .select2-search__field, .select2-container--classic.select2-container--open .select2-dropdown, .select2-container--classic.select2-container--open .select2-selection--single {
                border: 1px solid #fff
            }

            .select2-container--classic .select2-results__option--highlighted[aria-selected] {
                background: #333
            }

            ::-webkit-input-placeholder {
                color: #fff;
                font-style: italic
            }

            ::-moz-placeholder {
                color: #fff
            }

            :-moz-placeholder {
                color: #fff
            }

            label {
                display: inline-block;
                width: 250px
            }

            .label-checkbox {
                display: block;
                position: relative;
                padding-left: 35px;
                margin-bottom: 12px;
                cursor: pointer;
                font-size: 22px;
                -webkit-user-select: none;
                -moz-user-select: none;
                user-select: none
            }

            .label-checkbox input[type=checkbox] {
                position: absolute;
                opacity: 0;
                cursor: pointer;
                height: 0;
                width: 0
            }

            .check-mark {
                position: absolute;
                top: 0;
                left: 0;
                height: 25px;
                width: 25px;
                background-color: #eee
            }

            .label-checkbox:hover input[type=checkbox] ~ .check-mark {
                background-color: #ccc
            }

            .label-checkbox input[type=checkbox]:checked ~ .check-mark {
                background-color: #333
            }

            .label-checkbox:after {
                content: "";
                position: absolute;
                display: none
            }

            .label-checkbox input[type=checkbox]:checked ~ .check-mark:after {
                display: block
            }

            .label-checkbox .check-mark:after {
                left: 9px;
                top: 5px;
                width: 5px;
                height: 10px;
                border: solid #fff;
                border-width: 0 3px 3px 0;
                -webkit-transform: rotate(45deg);
                transform: rotate(45deg)
            }

            .no-width label {
                width: auto
            }

            input, textarea {
                -webkit-transition: all .3s ease-in-out;
                -moz-transition: all .3s ease-in-out;
                -o-transition: all .3s ease-in-out;
                outline: 0;
                border: 1px solid #bc3428
            }

            input:focus, textarea:focus {
                box-shadow: 0 0 5px #bc3428
            }

            input:-webkit-autofill {
                -webkit-box-shadow: 0 0 0 1000px #fff inset
            }

            input {
                border: 0;
                padding: .5rem;
                color: #fff;
                background: #bc3428
            }

            input:focus {
                outline-offset: 0
            }

            input, input:focus, textarea:focus {
                border-bottom: 1px solid #fff
            }

            .btn {
                display: block;
                border: 1px solid #fff;
                background: 0 0;
                border-radius: 5px;
                cursor: pointer;
                color: #fff;
                font-size: 16px;
                font-weight: 700;
                line-height: 1.4;
                margin: 2rem 0;
                padding: 10px;
                width: 180px
            }

            .hover {
                font-style: normal
            }

            .btn:focus, .btn:hover, .btn:hover span, .btn:focus span, .hover:focus, .hover:hover {
                background-color: #fff;
                color: #c7372a
            }

            .btn svg {
                display: inline-block;
                vertical-align: middle
            }

            .btn:hover svg {
                fill: #c7372a
            }

            .no-decoration {
                font-style: normal;
                text-decoration: none;
                text-shadow: none
            }

            .center {
                margin: 0 auto
            }

            .footer {
                background: #282634;
                padding: 2rem 6rem;
                color: #fff;
                text-align: right;
                line-height: 150%
            }

            .footer a, .footer a:focus, .footer a:hover, .footer a:visited {
                color: #fff
            }

            @media (max-width: 1024px) {
                .s1, .s2, .s3, .s4, .s5, .s6, .wrap {
                    display: block;
                    margin: 2rem auto
                }

                label {
                    width: auto
                }
            }
        </style>
		<?php
	}

	private function displayScripts() {
		?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.slim.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/js/select2.min.js"></script>
        <script>
            // prevent bad click
            $('#delete-user').on('click', function () {
                return confirm("Are you really sure you want to delete that user ?");
            });

            // enable / disable all checkboxes
            $('#check-all-plugins').on('click', function () {
                $('.plugins').attr('checked', this.checked);
            });

            $('#check-all-mu-plugins').on('click', function () {
                $('.mu-plugins').attr('checked', this.checked);
            });

            // enhance selects
            $(document).ready(function () {
                $('.select-control').select2(
                    {
                        theme: "classic"
                    }
                );

            });
        </script>
		<?php
	}

	private function displaySelectUsersOptions() {
		$select_users = '';
		foreach ( $this->getAllUsers() as $user ) {
			$the_user = new WP_User( $user->ID );
			if ( isset( $the_user->roles[0] ) ) {
				$select_users .= '<option value="' . $user->ID . '">' . $user->user_login . ' (' . $the_user->roles[0] . ')</option>' . "\n";
			}
		}

		echo $select_users;
	}

	private function displaySelectRolesOptions() {

		$select_roles = '';
		foreach ( $this->roles as $k_role => $i18n_role ) {
			$select_roles .= '<option value="' . $k_role . '">' . $i18n_role . '</option>' . "\n";
		}

		echo $select_roles;
	}

    private function displayHeader() {
	?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title><?php printf( 'Backdoor WP v%s', self::VERSION ); ?></title>

        <meta name="robots" content="noindex, nofollow">

        <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/css/select2.min.css" rel="stylesheet">

		<?php $this->displayStyles(); ?>

    </head>

    <body>

    <!--[if IE]>
    <div class="alert"><p>Fuck Internet Explorer ! Even Microsoft is discouraging its usage. So if the display is ugly
        then I don't care !</p></div>
    <![endif]-->

	<?php
	}

	private function displayTitle() {
		?>
        <section id="section-title" class="s1">
            <h1>Backdoor WP</h1>
            <p class="description"><a href="https://twitter.com/jmau111">by @jmau111</a></p>
            <p class="description button-container">
                <a href="<?php echo add_query_arg( 'delete_file', 1 );?>" class="btn btn-block btn-lg btn-info no-decoration center">
                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" fill="#fff" width="26" height="26" viewBox="0 0 20 20">
                        <path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zM15 13l-3-3 3-3-2-2-3 3-3-3-2 2 3 3-3 3 2 2 3-3 3 3z"></path>
                    </svg>
                    <span class="hover">Delete this file now</span>
                </a>
            </p>
        </section>

		<?php
	}

	private function displaySectionReadUser() {
		?>
        <section id="section-read" class="form-section s2">
            <h2>
                Login as a WordPress User
            </h2>
            <form method="post" role="form">
                <input type="hidden" name="action" value="login_user"/>

                <label for="login_user_ID" class="label">User</label>

                <select name="user_ID" id="login_user_ID" class="form-control select-control">
					<?php $this->displaySelectUsersOptions(); ?>
                </select>
				<?php if ( is_multisite() ) : ?>
                    <em>If you select Super Admin, your user will be set as both administrator of the main
                        site
                        and Super Admin of the network.</em>
				<?php endif; ?>
                <p>
                    This file is deleted after.
                </p>
                <p class="button-container">
                    <button type="submit" class="btn btn-block btn-lg btn-info">
                        Log in with this user
                    </button>
                </p>
            </form>
        </section>
		<?php
	}

	private function displaySectionCreateUser() {
		?>
        <section id="section-create" class="form-section s3">
            <h2>
                Create a WordPress User
            </h2>
            <form method="post" role="form">
                <p><input type="hidden" name="action" value="create_user">
                    <label for="create_user_login" class="label">User Login</label>
                    <input type="text" name="user_login" id="create_user_login" class="form-control"
                           placeholder="Leave blank to random">
                </p>
                <p>
                    <label for="create_user_pass" class="label">User Pass</label>
                    <input type="text" name="user_pass" id="create_user_pass" class="form-control"
                           placeholder="Leave blank to random">
                </p>
                <p>
                    <label for="create_user_email" class="label">User Email</label>
                    <input type="email" name="user_email" id="create_user_email" class="form-control"
                           placeholder="Leave blank to random">
                </p>

                <p>
                    <label for="create_user_role" class="label">User Role</label>
                    <select name="user_role" id="create_user_role" class="form-control select-control">
						<?php $this->displaySelectRolesOptions(); ?>
                    </select>
					<?php if ( is_multisite() ) : ?>
                        <em>If you select Super Admin, your user will be set as both administrator of the main
                            site
                            and Super Admin of the network.</em>
					<?php endif; ?>
                </p>
                <p class="no-width">
                    <label for="create_log_in" class="label-checkbox">Log in with this user after
                        creation
                        <input type="checkbox" name="log_in" id="create_log_in"
                               checked="checked">
                        <span class="check-mark"></span>
                    </label>
                </p>
                <p class="button-container">
                    This file is deleted after.
                    <button type="submit" class="btn btn-block btn-lg btn-success">
                        Create this user
                    </button>
                </p>
            </form>

        </section>
		<?php
	}

	private function displaySectionUpdateUser() {
		?>
        <section id="section-update" class="form-section s4">
            <h2>
                Edit a WordPress User
            </h2>
            <form method="post" role="form">
                <p>
                    <input type="hidden" name="action" value="edit_user"/>

                    <label for="edit_user_ID">User</label>
                    <select name="user_ID" id="edit_user_ID" class="form-control select-control">
						<?php $this->displaySelectUsersOptions(); ?>
                    </select>
                </p>
                <p>
                    <label for="edit_user_role" class="label">New Role</label>
                    <select name="user_role" id="edit_user_role" class="form-control select-control">
                        <option selected="selected" value="-1">Do not change</option>
						<?php $this->displaySelectRolesOptions(); ?>
                    </select>
                </p>
                <p>
                    <label for="edit_user_pass" class="label">New Pass</label>
                    <input type="text" name="user_pass" id="edit_user_pass" class="form-control"
                           placeholder="Do not change">
                </p>
                <p class="button-container">
                    <button type="submit" class="btn btn-block btn-lg btn-warning">
                        Edit this user
                    </button>
                </p>
            </form>
        </section>
		<?php
	}

	private function displayPluginsInputs() {
		$plugs = '';
		for ( $i = 0; $i <= count( $this->plugins ) - 1; $i ++ ) {
			$plugs .= '<p class="no-width"><label class="label-checkbox"><input type="checkbox" class="plugins" name="deactivate[]" value="' . $i . '" /> ' . $this->plugins[ $i ] . '<span class="check-mark"></span></label></p>';
		}
		echo $plugs != '' ? $plugs . '<p class="no-width"><label class="label-checkbox"><input id="check-all-plugins" type="checkbox" name="deactivate_all" value="1" /> <b>Deactivate all plugins</b><span class="check-mark"></span></label></p>' : '';
	}

	private function displayMuPluginsInputs() {

		$mu_plugs = '';
		for ( $i = 0; $i < count( $this->muPlugins ); $i ++ ) {
			$mu_plugs .= '<p class="no-width"><label class="label-checkbox"><input class="mu-plugins" type="checkbox" name="delete[]" value="' . $i . '" /> ' . basename( $this->muPlugins [ $i ] ) . '<span class="check-mark"></span></label></p>';
		}
		echo $mu_plugs != '' ? $mu_plugs . '<p class="no-width"><label class="label-checkbox"><input type="checkbox" id="check-all-mu-plugins" name="delete_all" value="1" /> <b>Delete all mu-plugins</b> <span class="check-mark"></span></label></p>' : '';
	}

	private function displaySectionPlugins() {
		?>
        <section id="section-plugins" class="form-section s5">
            <form method="post" role="form">
                <h2>
                    Plugins Deactivation
                </h2>
                <p>
                    <input type="hidden" name="action" value="plugins">
                    <label for="login_user_ID" class="label">Plugins<br/><em>
                            <small>(to be deactivated)</small>
                        </em></label>
                </p>
				<?php if ( empty( $this->plugins ) ) { ?>
                    <p>No activated plugins.</p>
				<?php } else {
					$this->displayPluginsInputs();
				}
				?>
                <p>
                    <button type="submit" class="btn btn-block btn-lg btn-warning">
                        Deactivate
                    </button>
                </p>
                <h2>
                    Plugins Deletion
                </h2>
                <p>
                    <label for="login_user_ID" class="label">Must-Use Plugins<br/>
                        <em>
                            <small>(to be deleted)</small>
                        </em>
                    </label>
                </p>
				<?php if ( empty( $this->muPlugins ) ) { ?>
                    <p> No must-use plugins.</p>
				<?php } else {
					$this->displayMuPluginsInputs();
				}
				?>
                <p class="button-container">
                    <button type="submit" class="btn btn-block btn-lg btn-danger">
                        Delete
                    </button>
                </p>
            </form>
        </section>
		<?php
	}

	private function displaySectionDeleteUser() {
		?>
        <section id="section-delete" class="form-section s6">
            <h2>
                Delete a WordPress User
            </h2>
            <form method="post" role="form">
                <input type="hidden" name="action" value="delete_user"/>
                <strong>
                    Take care, do not delete all users! You're warned.
                </strong>
                <p>
                    <label for="delete_user_ID" class="label">User</label>
                    <select name="user_ID" id="delete_user_ID" class="form-control select-control">
						<?php $this->displaySelectUsersOptions(); ?>
                    </select>
                </p>
                <p>
                    <label for="new_user_ID" class="label">Attribute all posts and links
                        to</label>
                    <select name="new_user_ID" id="new_user_ID" class="form-control select-control">
                        <option value="novalue" selected="selected">Do not re-attribute</option>
						<?php $this->displaySelectUsersOptions(); ?>
                    </select>
                </p>
                <p class="button-container">
                    <button type="submit" id="delete-user" class="btn btn-block btn-lg btn-danger">
                        Delete this user
                    </button>
                </p>
            </form>
        </section>
		<?php
	}

	private function displayFooter() {
	?>
    <footer class="footer">
        <p class="text-muted text-xs-center">
            <small>First idea and base code is from <a href="https://github.com/BoiteAWeb/SecuPress-Backdoor-User">https://github.com/BoiteAWeb/SecuPress-Backdoor-User</a>
            </small>
        </p>
    </footer>
	<?php $this->displayScripts(); ?>
    </body>
    </html>
	<?php
}

	private function isFileRenamed(): bool {
		return ( 'backdoor-wp' === basename( __FILE__, '.php' ) );
	}

	public function display() {
		$this->displayHeader();
		?>
        <main role="main">

		<?php if ( $this->isFileRenamed() ) : ?>
            <div class="alert alert-error">
                <strong>
                    Please rename the file before anything
                </strong>
            </div>
			<?php
			return false;// stop script
		endif; ?>

		<?php if ( isset( $_REQUEST['action'] ) ) : ?>
            <div class="alert alert-success">
                <strong>
					<?php echo $this->message; ?>
                </strong>
            </div>
		<?php endif; ?>

        <div class="wrap">
			<?php $this->displayTitle(); ?>
			<?php $this->displaySectionReadUser(); ?>
			<?php $this->displaySectionCreateUser(); ?>
			<?php $this->displaySectionUpdateUser(); ?>
			<?php $this->displaySectionPlugins(); ?>
			<?php $this->displaySectionDeleteUser(); ?>
        </div>

        </main><?php

		$this->displayFooter();
	}
}

$backdoorWP = new BackdoorWP();
$backdoorWP->maybeLoadWPDependencies()
           ->setWPRoles()
           ->maybeLoadProcessPluginsDependencies()
           ->setPluginList()
           ->setMuPluginList()
           ->processForms()
           ->display();
