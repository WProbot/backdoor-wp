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
	private $message;
	private $userId;

	const VERSION = '1.0';

	public function __construct() {
		$this->plugins   = [];
		$this->muPlugins = [];
		$this->roles     = [];
		$this->message   = '';
		$this->userId    = 0;
	}

	public function maybeLoadWPDependcies() {

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

	public function setWPRoles() {
		$roles = new WP_Roles();
		$roles = $roles->get_names();
		$roles = array_map( 'translate_user_role', $roles );

		if ( is_multisite() ) {
			$roles = array_merge( [ 'site_admin' => 'Super Admin' ], $roles );
		}

		$this->roles = $roles;

		return $this;
	}

	public function setPluginList( array $value = [] ) {

		if ( empty( $value ) ) {
			$this->plugins = get_option( 'active_plugins', [] );
		}

		$this->plugins = array_filter( $value );

		return $this;
	}

	public function setMuPluginList( array $value = [] ) {

		if ( empty( $value ) ) {
			$this->muPlugins = wp_get_mu_plugins();
		}

		$this->muPlugins = array_filter( $value );

		return $this;
	}

	public function getWPRoles() {
		return $this->roles;
	}

	public function getPluginList() {
		return $this->plugins;
	}

	public function getMuPluginList() {
		return $this->muPlugins;
	}

	public function getAllUsers() {

		global $wpdb;
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

	public function getUserData() {

		return [
			'new_user_email' => ! empty( $_REQUEST['user_email'] ) && str_replace( ' ', '+', $_REQUEST['user_email'] != '' ? $_REQUEST['user_email'] : time() . '@fake' . time() . '.com' ),
			'new_user_pass'  => ! empty( $_REQUEST['user_pass'] ) ? $_REQUEST['user_pass'] : time(),
			'new_user_role'  => ! empty( $_REQUEST['user_role'] ) && array_key_exists( $_REQUEST['user_role'], $this->roles ) ? $_REQUEST['user_role'] : 'administrator',
			'new_user_login' => ! empty( $_REQUEST['user_login'] ) ? $_REQUEST['user_login'] : $this->user['new_user_role'] . '_' . substr( md5( uniqid() . time() ), 0, 7 ),
		];
	}

	private function isAction( $action ) {
		return isset( $_REQUEST['action'] ) && $action === $_REQUEST['action'];
	}

	private function createUser() {

		if ( ! $this->isAction( 'create_user' ) ) {
			return false;
		}

		if ( username_exists( $this->user['new_user_login'] ) ) {
			wp_die( new WP_Error( 'existing_user_login', 'This username is already registered.' ) );
		}

		$this->userId = wp_create_user( $this->user['new_user_login'], $this->user['new_user_pass'], $this->user['new_user_email'] );

		if ( is_wp_error( $this->userId ) ) {
			wp_die( new WP_Error( 'registerfail', sprintf( '<strong>ERROR</strong>: Couldn&#8217;t register you... please contact the <a href="mailto:%s">webmaster</a> !' ), esc_attr( get_option( 'admin_email' ) ) ) );
		}
		$user = new WP_User( $this->userId );

		if ( is_multisite() && 'site_admin' === $this->user['new_user_role'] ) {
			grant_super_admin( $this->userId );
			$user->set_role( 'administrator' );
		} else {
			$user->set_role( $this->user['new_user_role'] );
		}

		if ( isset( $_REQUEST['log_in'] ) ) {
			wp_signon( [
				'user_login'    => $this->user['new_user_login'],
				'user_password' => $this->user['new_user_pass']
			] );

			$this->deleteFile();

			wp_redirect( admin_url( 'profile.php' ) );
			die();
		}
		$this->message = 'User created!';
	}

	private function processPlugins() {

		if ( ! $this->isAction( 'plugins' ) ) {
			return false;
		}

		$deactivate = [];

		if ( ( isset( $_POST['deactivate'] ) && is_array( $_POST['deactivate'] ) ) || isset( $_POST['deactivate_all'] ) ) {
			require_once( 'wp-includes/formatting.php' );
			if ( ! isset( $_POST['deactivate_all'] ) ) {
				foreach ( $_POST['deactivate'] as $des ) {
					if ( (int) $des == $des && isset( $plugins[ $des ] ) ):
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
			$this->message = 'Plugins deactivated!';
		}

		// Delete mu plugins
		if ( ( isset( $_POST['delete'] ) && is_array( $_POST['delete'] ) ) || isset( $_POST['delete_all'] ) ) {
			require_once( 'wp-includes/formatting.php' );
			// We do not delete all
			if ( ! isset( $_POST['delete_all'] ) ) {
				foreach ( $_POST['delete'] as $del ) {
					if ( (int) $del == $del && isset( $this->muPlugins[ $del ] ) ):
						$delete[] = $this->muPlugins[ $del ];
						@unlink( $this->muPlugins[ $del ] );
						unset( $this->muPlugins[ $del ] );
					endif;
				}
				// reorder array
				$this->muPlugins = array_values( $this->muPlugins );
			} else { // delete all
				foreach ( $this->muPlugins as $mup ) {
					@unlink( $mup );
				}
				// empty $this->muPlugins array
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

			if ( is_multisite() && 'site_admin' === $this->user['new_user_role'] ) {
				grant_super_admin( $user->ID );
				$user->set_role( 'administrator' );
			} else {
				$user->set_role( $this->user['new_user_role'] );
			}

			$this->message = 'User updated!';
		}

		// If a pass change is needed
		if ( $_REQUEST['user_pass'] != '' ) {
			// update the member's pass
			wp_update_user( [
				'ID'        => $_REQUEST['user_ID'],
				'user_pass' => $_REQUEST['user_pass']
			] );
			$this->message = 'User updated!';
		}
	}

	private function login() {

		if ( ! $this->isAction( 'login_user' ) ) {
			return false;
		}

		$user_data = get_userdata( $_REQUEST['user_ID'] );

		wp_set_current_user( $user_data->ID, $user_data->user_login );
		wp_set_auth_cookie( $user_data->ID );
		do_action( 'wp_login', $user_data->user_login, $user_data );

		$this->deleteFile();

		wp_redirect( admin_url( 'index.php' ) );
		die();
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

		if ( ! $this->isAction( 'delete_file' ) ) {
			return false;
		}

		unlink( __FILE__ );
		$this->message = 'File deleted!';
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

        </head>

        <body>
		<?php
		$menu   = [ 'dash', 'create', 'read', 'update', 'delete', 'plugins' ];
		$action = isset( $_POST['action'] ) ? htmlspecialchars( $_POST['action'] ) : 'dash';

		foreach ( $menu as $slug ) {
			?>
            <input type="radio" name="menu_display" id="menu_<?php echo $slug; ?>"
                   value="<?php echo $slug; ?>" <?php checked( $action, $slug ); ?>>
			<?php
		}
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
		foreach ( $this->roles as $krole => $i18nrole ) {
			$select_roles .= '<option value="' . $krole . '">' . $i18nrole . '</option>' . "\n";
		}

		echo $select_roles;
	}

	private function displayInstructions() {
		?>
        <section id="section-dash">
            <p>Log in as a WordPress user if you only have the (s)FTP access.</p>
            <p>You can create or edit a user, log in as an existing one.</p>
            <p>If you need to deactivate a plugin without being logged in, you can do it.</p>
            <p>Just use the top menu and you're done.</p>
        </section>

		<?php
	}

	private function displaySectionReadUser() {

		$this->login();
		?>
        <section id="section-read" class="form-section">
            <h2>
                Login as a WordPress User
            </h2>
            <form method="post" role="form">
                <input type="hidden" name="action" value="login_user"/>

                <label for="login_user_ID" class=" col-form-label">User</label>
                <div class="select-container">
                    <select name="user_ID" id="login_user_ID" class="form-control select-control">
						<?php $this->displaySelectUsersOptions(); ?>
                    </select>
					<?php if ( is_multisite() ) : ?>
                        <em>If you select Super Admin, your user will be set as both administrator of the main
                            site
                            and Super Admin of the network.</em>
					<?php endif; ?>
                </div>

                <p>
                    This file is deleted after.
                </p>
                <button type="submit" class="btn btn-block btn-lg btn-info">
                    <i class="fa fa-user-secret"></i>
                    Log in with this user
                </button>
            </form>
        </section>
		<?php
	}

	private function displaySectionCreateUser() {
		$this->createUser();
		?>
        <section id="section-create" class="form-section">
            <h2>
                Create a WordPress User
            </h2>
            <form method="post" role="form">
                <input type="hidden" name="action" value="create_user">

                <div class="form-group row">
                    <label for="create_user_login" class=" col-form-label">User Login</label>
                    <div class="simple-div">
                        <input type="text" name="user_login" id="create_user_login" class="form-control"
                               placeholder="Leave blank to random">
                    </div>
                </div>

                <div class="form-group row">
                    <label for="create_user_pass" class=" col-form-label">User Pass</label>
                    <div class="simple-div">
                        <input type="text" name="user_pass" id="create_user_pass" class="form-control"
                               placeholder="Leave blank to random">
                    </div>
                </div>

                <div class="form-group row">
                    <label for="create_user_email" class=" col-form-label">User Email</label>
                    <div class="simple-div">
                        <input type="email" name="user_email" id="create_user_email" class="form-control"
                               placeholder="Leave blank to random">
                    </div>
                </div>

                <div class="form-group row">
                    <label for="create_user_role" class=" col-form-label">User Role</label>
                    <div class="simple-div">
                        <select name="user_role" id="create_user_role" class="form-control select-control">
							<?php $this->displaySelectRolesOptions(); ?>
                        </select>
						<?php if ( is_multisite() ) : ?>
                            <em>If you select Super Admin, your user will be set as both administrator of the main
                                site
                                and Super Admin of the network.</em>
						<?php endif; ?>
                    </div>
                </div>

                <div class="form-group row">
                    <label for="create_log_in" class=" col-form-label">Log in with this user after
                        creation</label>
                    <div class="simple-div">
                        <div class="form-check">
                            <label class="form-check-label">
                                <input type="checkbox" class="form-check-input" name="log_in" id="create_log_in"
                                       checked="checked">
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group row">
                    <div class="col-sm-9">
                        This file is deleted after.
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="btn btn-block btn-lg btn-success">
                            <i class="fa fa-user-plus"></i>
                            Create this user
                        </button>
                    </div>
                </div>
            </form>
            <hr>
        </section>
		<?php
	}

	private function displaySectionUpdateUser() {
		?>
        <section id="section-update" class="form-section">
            <h2>
                <small class="fa-stack fa-lg text-warning">
                    <i class="fa fa-circle fa-stack-2x"></i>
                    <i class="fa fa-stack-1x fa-inverse fa-vcard"></i>
                </small>
                Edit a WordPress User
            </h2>
            <form method="post" role="form">
                <input type="hidden" name="action" value="edit_user"/>

                <div class="form-group row">
                    <label for="edit_user_ID" class=" col-form-label">User</label>
                    <div class="simple-div">
                        <select name="user_ID" id="edit_user_ID" class="form-control select-control">
							<?php $this->displaySelectUsersOptions(); ?>
                        </select>
                    </div>
                </div>

                <div class="form-group row">
                    <label for="edit_user_role" class=" col-form-label">New Role</label>
                    <div class="simple-div">
                        <select name="user_role" id="edit_user_role" class="form-control select-control">
                            <option selected="selected" value="-1">Do not change</option>
							<?php $this->displaySelectRolesOptions(); ?>
                        </select>
                    </div>
                </div>

                <div class="form-group row">
                    <label for="edit_user_pass" class=" col-form-label">New Pass</label>
                    <div class="simple-div">
                        <input type="text" name="user_pass" id="edit_user_pass" class="form-control"
                               placeholder="Do not change">
                    </div>
                </div>

                <div class="form-group row">
                    <div class="col-sm-9">
                        <div class="alert alert-warning" role="alert">
                            <small>
                                <i class="fa fa-warning"></i>
                                Do not forget to delete this file after use!
                            </small>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="btn btn-block btn-lg btn-warning">
                            <i class="fa fa-vcard"></i>
                            Edit this user
                        </button>
                    </div>
                </div>
            </form>
            <hr>
        </section>
		<?php
	}

	private function displayPluginsInputs() {
		$plugs = '';
		for ( $i = 0; $i <= count( $this->plugins ) - 1; $i ++ ) {
			$plugs .= '<p><label><input type="checkbox" name="deactivate[]" value="' . $i . '" /> ' . $this->plugins[ $i ] . '</label></p>';
		}
		echo $plugs != '' ? $plugs . '<p><label><input type="checkbox" name="deactivate_all" value="1" /> <b>Deactivate all plugins</b></label></p>' : '';
	}

	private function displayMuPluginsInputs() {

		$mu_plugs = '';
		for ( $i = 0; $i < count( $this->muPlugins ); $i ++ ) {
			$mu_plugs .= '<p><label><input type="checkbox" name="delete[]" value="' . $i . '" /> ' . basename( $this->muPlugins [ $i ] ) . '</label></p>';
		}
		echo $mu_plugs != '' ? $mu_plugs . '<p><label><input type="checkbox" name="delete_all" value="1" /> <b>Delete all mu-plugins</b></label></p>' : '';
	}

	private function displaySectionPlugins() {
		?>
        <section id="section-plugins" class="form-section">
            <form method="post" role="form">
                <h2>
                    Plugins Deactivation
                </h2>
                <input type="hidden" name="action" value="plugins">
                <div class="form-group row">
                    <label for="login_user_ID" class=" col-form-label">Plugins<br><em>
                            <small>(to be deactivated)</small>
                        </em></label>
                    <div class="simple-div">
						<?php if ( empty( $this->plugins ) ) { ?>
                            <p>No activated plugins.</p>
						<?php } else {
							$this->displayPluginsInputs();
						}
						?>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="col-sm-9">
                        <div class="alert alert-warning" role="alert">
                            <small>
                                <i class="fa fa-warning"></i>
                                Do not forget to delete this file after use!
                            </small>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="btn btn-block btn-lg btn-warning">

                            Deactivate
                        </button>
                    </div>
                </div>
                <h2>
                    Plugins Deletion
                </h2>
                <div class="form-group row">
                    <label for="login_user_ID" class=" col-form-label">Must-Use Plugins<br><em>
                            <small>(to be deleted)</small>
                        </em></label>
                    <div class="simple-div">
						<?php if ( empty( $this->muPlugins ) ) { ?>
                            <p>No must-use plugins.</p>
						<?php } else {
							$this->displayMuPluginsInputs();
						}
						?>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="col-sm-9">
                        <div class="alert alert-warning" role="alert">
                            <small>
                                <i class="fa fa-warning"></i>
                                Do not forget to delete this file after use!
                            </small>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="btn btn-block btn-lg btn-danger">
                            <i class="fa fa-trash"></i>
                            Delete
                        </button>
                    </div>
                </div>
            </form>
        </section>
		<?php
	}

	private function displaySectionDelete() {
		?>
        <section id="section-delete" class="form-section">
            <h2>
                Delete a WordPress User
            </h2>
            <form method="post" role="form">
                <input type="hidden" name="action" value="delete_user"/>

                <div class="alert alert-warning text-xs-center" role="alert">
                    <i class="fa fa-warning"></i>
                    <strong>
                        Take care, do not delete all users! You're warned.
                    </strong>
                </div>

                <div class="form-group row">
                    <label for="delete_user_ID" class=" col-form-label">User</label>
                    <div class="simple-div">
                        <select name="user_ID" id="delete_user_ID" class="form-control select-control">
							<?php $this->displaySelectUsersOptions(); ?>
                        </select>
                    </div>
                </div>

                <div class="form-group row">
                    <label for="new_user_ID" class=" col-form-label">Attribute all posts and links
                        to</label>
                    <div class="simple-div">
                        <select name="new_user_ID" id="new_user_ID" class="form-control select-control">
                            <option value="novalue" selected="selected">Do not re-attribute</option>
							<?php $this->displaySelectUsersOptions(); ?>
                        </select>
                    </div>
                </div>

                <div class="form-group row">
                    <div class="col-sm-9">
                        <div class="alert alert-warning" role="alert">
                            <small>
                                <i class="fa fa-warning"></i>
                                Do not forget to delete this file after use!
                            </small>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="btn btn-block btn-lg btn-danger">
                            <i class="fa fa-user-times"></i>
                            Delete this user
                        </button>
                    </div>
                </div>
            </form>
        </section>
		<?php
	}

	public function display() {

		$this->displayHeader();
		?>
        <main role="main">
        <div class="container">
			<?php if ( isset( $_REQUEST['action'] ) ) : ?>
                <div class="alert alert-success" role="alert">
                    <i class="fa fa-check"></i>
                    <strong>
						<?php echo $this->message; ?>
                    </strong>
                </div>
			<?php endif; ?>

			<?php $this->displayInstructions(); ?>
			<?php $this->displaySectionReadUser(); ?>
			<?php $this->displaySectionCreateUser(); ?>
			<?php $this->displaySectionUpdateUser(); ?>
			<?php $this->displaySectionPlugins(); ?>
			<?php $this->displaySectionDelete(); ?>


        </div>
        </main><?php

		$this->displayFooter();
	}

	private function displayFooter() {
		?>
        <footer class="footer" role="siteinfo">
            <div class="container">
                <div class="row">
                    <div class="text-xs-center simple-div">
                        <div class="alert alert-danger" role="alert">
                            <i class="fa fa-warning"></i>
                            <strong class="text-uppercase">
                                Do not forget to delete this file after use!
                            </strong>
                            <!-- <a class="alert-link" href="?action=delete_file">Click here to delete it now!</a> -->
                        </div>
                    </div>
                    <div class="">
                        <a class="btn btn-lg btn-block btn-danger" href="?action=delete_file">
                            <i class="fa fa-times"></i>
                            Click here to delete it now!
                        </a>
                    </div>
                </div>
                <p class="text-muted text-xs-center">
                    <span>First idea is from <a href="https://github.com/BoiteAWeb/SecuPress-Backdoor-User">https://github.com/BoiteAWeb/SecuPress-Backdoor-User</a></span>
                </p>

            </div>
        </footer>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.slim.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/js/select2.min.js"></script>
        <script>
            $(document).ready(function () {
                $('.select-control').select2();
            });
        </script>
        </body>
        </html>
		<?php
	}

}

$backdoorWP = new BackdoorWP();
$backdoorWP->maybeLoadWPDependcies()
           ->setWPRoles()
           ->setPluginList()
           ->setMuPluginList()
           ->display();
