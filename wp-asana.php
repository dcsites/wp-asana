<?php
/**
 * Plugin Name: WP Asana
 * Version: 0.1
 * Plugin URI: https://github.com/drunken-coding/wp-asana
 * Description: Adds Asana integration into WordPress for limited client access
 * Author: ryanshoover
 * Author URI: http://dcsit.es
 * Text Domain: wp-asana
 */

// Maybe add an option for tracking the upgrade notice
add_option( 'asana_show_upgrade_notice', '' );

add_action( 'wp_enqueue_scripts', 'asana_scripts' );
if ( is_admin() ) { // admin actions
	add_action( 'admin_menu', 'create_asana_options_page' );
	add_action( 'admin_init', 'register_asana_settings' );
	add_action( 'admin_init', 'ignore_asana_settings_notice' );
	add_action( 'admin_notices', 'asana_settings_notice' );
}

add_shortcode( 'asana-tasks', 'asana_show_tasks' );
add_shortcode( 'asana-form', 'asana_show_task_form' );

function asana_scripts() {
	wp_enqueue_style( 'font-awesome', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.4.0/css/font-awesome.min.css' );
	wp_enqueue_style( 'wp-asana-css', plugin_dir_url( __FILE__ ) . '/css/style.css' );
}

function create_asana_options_page() {
	$asana_page_title = __( 'User Asana Settings', 'wp-asana' );
	$asana_menu_title = __( 'Asana', 'wp-asana' );
	add_options_page( $asana_page_title, $asana_menu_title, 'manage_options', 'asana', 'asana_options_page' );
}

function register_asana_settings() {
	register_setting( 'wp-asana-options', 'asana_api_key' );
	register_setting( 'wp-asana-options', 'asana_user_workspaces' );
}

function asana_options_page() {

	global $wpdb;

	$api_key = esc_attr( get_option( 'asana_api_key', false ) );

	$form_open = <<<FORM
	<h2>%s</h2>
	<form method="post" action="options.php">
FORM;

	printf( $form_open, __( 'User Asana Settings', 'wp-asana' ) );

	settings_fields( 'wp-asana-options' );
	do_settings_sections( 'wp-asana-options' );

	$table_open = <<<TABLE
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><label for="asana_api_key">%s</label></th>
			<td>
				<input name="asana_api_key" id="asana_api_key" value="$api_key">
				<span class="description">%s</span>
			</td>
		</tr>
TABLE;

	printf( $table_open, __( 'Asana API key', 'wp-asana' ), __( 'Your personal API key', 'wp-asana' ) );

	if ( ! empty( $api_key ) ) {
		$workspace_url = 'workspaces';
		$workspaces = get_asana_info( $workspace_url, $api_key ); //note API key must have colon added to it for basic auth to work

		$users = get_users();
		$user_workspaces = get_option( 'asana_user_workspaces', false );

		foreach ( $users as $user ) {

			echo '<tr valign="top">';

			$ws_user = isset( $user_workspaces[ $user->ID ] ) ? $user_workspaces[ $user->ID ] : '';

			echo "<th scope=\"row\"><label for=\"asana_user_workspaces[{$user->ID}]\">{$user->display_name}</label></th>";

			echo "<td><select name='asana_user_workspaces[$user->ID]' id='asana_user_workspaces'>";

			if ( empty( $ws_user ) ) {
				echo "<option value=''>Choose a workspace</option>";
			}

			foreach ( $workspaces as $ws ) {
				$ws_value = $ws->id . '&' . $ws->name;
				$selected = $ws_user == $ws_value ? ' selected' : '';
				echo "<option value=\"{$ws_value}\"{$selected}>{$ws->name}</option>";
			}

			echo '</select></td>';

			echo '</tr>';
		}
	}
	else {
		echo 'Please enter an API key and save your options, then come back to select a workspace';
	}

	echo '</table>';

	submit_button();

	echo '</form>';
	echo '</div>';
}

function asana_show_tasks(){

	if ( ! is_user_logged_in() ) {
		echo '<p>' . __( 'Please log in to see your tasks', 'wp-asana' ) . '</p>';
		wp_login_form();
		return false;
	}

	$user_id = get_current_user_id();
	$api_key = get_option( 'asana_api_key' );

	$user_workspaces = get_option( 'asana_user_workspaces' );
	$ws_string = isset( $user_workspaces[ $user_id ] ) ? $user_workspaces[ $user_id ] : '';
	$ws_values = explode( '&', $ws_string );
	$ws_value = $ws_values[0];

	//can't create projects until the plugin has been configured and the user has an Asana project
	if ( empty( $api_key ) || empty( $ws_value ) ){
		return false;
	}

	$return = '';

	if ( isset( $_POST['update_asana'] ) && $_POST['update_asana'] ) {
		//get all the values of the checked tasks
		$tasks_to_update = $_POST['asanatask'];

		//for each task
		foreach ( $tasks_to_update as $task ){

			$completed = array( 'completed' => true );
			$body = array( 'data' => $completed );
			$url = 'tasks/' . $task;

			//call the API
			$response = put_asana_info( $url, 'PUT', $body, $api_key );
			$response_r = $response['response'];
			$code = $response_r['code'];

			if ( $code == 200 ){
				$return .= 'Task successfully updated.';
			} else {
				$return .= 'There was a problem communicating with Asana.  The error returned was ' . $response_r['message'];
			}
		}
	}

	//set correct timezone so that we get the right date for tasks
	$timezone = get_option( 'timezone_string', 'UTC+0' );
	date_default_timezone_set( $timezone );

	//get the names of the projects
	$projects_url = "workspaces/$ws_value/projects";
	$projects = get_asana_info( $projects_url, $api_key );

	$total_tasks = 0;

	if ( empty( $projects ) ) {
		return '<p>' . __( 'There are no tasks in this workspace', 'wp-asana' ) . '</p>';
	}

	foreach ( $projects as $p ){

		if ( ! $p->id ) {
			continue;
		}

		$task_url_ending = "tasks?opt_fields=name,completed,due_on&project=$p->id";
		$tasks = get_asana_info( $task_url_ending, $api_key );

		$return .= '<h3>' . $p->name . '</h3>';

		//if there are no tasks in the category, say so
		if ( ! empty( $tasks ) ) {
			$return .= '<ul class="tasks">';

			$in_subproject = 0;
			$subproject_tasks = 0;

			foreach ( $tasks as $t ) {

				if ( 1 == $t->completed || empty( $t->name ) ) {
					continue;
				}

				$is_subproject = ':' == substr( $t->name, -1 ) ? true : false;

				if ( $is_subproject && $in_subproject && ! $subproject_tasks ) {
					$return .= '</ul>';
				}

				if ( $total_tasks ) {
					$return .= '</li>';
				}

				if ( $is_subproject && $in_subproject && $subproject_tasks ) {
					$return .= '</ul>';
				}

				$total_tasks++;
				$subproject_tasks++;

				$return .= '<li>';

				if ( $is_subproject ) {
					$return .= '<strong>';
				}

				$return .= $t->name;

				$color = strtotime( $t->due_on ) < time() ? 'red' : 'grey';

				if ( ! empty( $t->due_on ) ) {
					$return .= " - <span class='$color'>$t->due_on</span>";
				}

				if ( $is_subproject ) {
					$return .= '</strong>';
				}

				if ( $is_subproject ) {
					$return .= '<ul>';
					$in_subproject++;
					$subproject_tasks = 0;
				}
			}

			if ( $is_subproject || $subproject_tasks ) {
				$return .= '</ul>';
			}

			$return .= '</li></ul>';
		} else {
			$return .= '<p>' . __( 'There are no tasks in this project', 'wp-asana' ) . '</p>';
		}
	}

	if ( ! $total_tasks ) {
		$return .= '<p>' . __( 'There are no tasks in this workspace', 'wp-asana' ) . '</p>';
	}

	return $return;
}

function asana_show_task_form(){

	if ( ! is_user_logged_in() ) {
		return false;
	}

	$user_id = get_current_user_id();
	$api_key = get_option( 'asana_api_key' );

	$user_workspaces = get_option( 'asana_user_workspaces' );
	$ws_string = isset( $user_workspaces[ $user_id ] ) ? $user_workspaces[ $user_id ] : '';
	$ws_values = explode( '&', $ws_string );
	$ws_value = $ws_values[0];

	//can't create projects until the plugin has been configured and the user has an Asana project
	if ( empty( $api_key ) || empty( $ws_value ) ){
		return false;
	}

	$return = '';

	//call task creation when form is submitted
	if ( isset( $_POST['asana_create_task'] ) && $_POST['asana_create_task'] ) {
		//get variables and set up data
		$name = $_POST['asana_new_task_name'];
		$notes = $_POST['asana_new_task_notes'];
		$project = $_POST['asana_project'];
		$due_date = $_POST['asana_due_date'];
		$url = 'tasks';
		$method = 'POST';

		$bodydata = array(
			'name' => $name,
			'workspace' => $ws_value,
			'assignee' => 'me',
			);

		if ( ! empty( $notes ) ){
			$bodydata['notes'] = $notes;
		}
		if ( ! empty( $due_date ) ){
			$bodydata['due_on'] = $due_date;
		}

		$body = array( 'data' => $bodydata );

		//call task creation
		$response = put_asana_info( $url, $method, $body, $api_key );


		if ( is_wp_error( $response ) ) {
			return 'Error communicating with Asana: ' . $response->get_error_message();
		} else {
			$response_r = $response['response'];
			$code = $response_r['code'];

			if ( 201 == $code ){
				$return .= 'Task successfully created.';
			} else {
				return 'There was a problem communicating with Asana.  The error returned was ' . $response_r['message'];
			}

			//call the api again to associate it with the project
			$response_body = json_decode( $response['body'] );
			$data = $response_body->data;
			$id = $data->id;
			$url = 'tasks/'.$id.'/addProject';
			$bodydata = array( 'project' => $project );
			$body = array( 'data' => $bodydata );
			$response = put_asana_info( $url, 'POST', $body, $api_key );
			$response_r = $response['response'];
			$code = $response_r['code'];
			if ( $code == 200 ){
				$return .= '  Task added to project.';
			} else {
				$return .= '  There was a problem adding the task to the project.  The error returned was '.$response_r['message'];
			}
		}
	}

	//create form
	$projects_url = "workspaces/$ws_value/projects";
	$projects = get_asana_info( $projects_url, $api_key );

	$url = get_permalink();

	$form = <<<FORM
	<form action='{$url}' method='post' enctype='multipart/form-data'>
		<table>
			<tr>
				<td>%s</td>
				<td><input type='text' size='50'name='asana_new_task_name' ";"id='asana_new_task_name' value='' /></td>
			</tr>
			<tr>
				<td>%s</td>
				<td><textarea name='asana_new_task_notes' id='asana_new_task_notes' ></textarea></td>
			</tr>
			<tr>
				<td>%s</td>
				<td>
					<select name='asana_project' id='asana_project'>
						<option value=''>%s</option>
						%s
					</select>
				</td>
			</tr>
			<tr>
				<td>%s</td>
				<td>
					<input type='date' name='asana_due_date' id='asana_due_date' max='%d' min='%d' />
				</td>
			</tr>
		</table>
		<input type='submit' name ='asana_create_task' value='%s' />
	</form>
FORM;

	$options = '';
	if ( is_array( $projects ) ) {
		foreach ( $projects as $p ) {
			$options .= "<option value='$p->id'>$p->name</option>";
		}
	}

	$date_max = date( 'Y-m-d', time() + 31536000 );
	$date_min = date( 'Y-m-d' );

	$return .= sprintf( $form,
		__( 'Task name', 'wp-asana' ),
		__( 'Notes', 'wp-asana' ),
		__( 'Project', 'wp-asana' ),
		__( 'Choose a project', 'wp-asana' ),
		$options,
		__( 'Due Date', 'wp-asana' ),
		$date_max,
		$date_min,
		__( 'Create Task', 'wp-asana' )
	);

	return $return;
}

function get_asana_info($url_ending, $api_key){
	$get_param = strpos( $url_ending, '?' ) ? '&' : '?';
	$asana_url = 'https://app.asana.com/api/1.0/' . $url_ending . $get_param . 'access_token=' . $api_key;
	$data = false;

	$results = wp_remote_get( $asana_url );

	if ( ! is_wp_error( $results ) ) {
		//get results
		$resultsJson = json_decode( $results['body'] );

		if ( isset( $resultsJson->data ) ) {
			$data = $resultsJson->data;
		}
	}
	return $data;
}


function put_asana_info( $url_ending, $method, $data, $api_key ){

	$url = 'https://app.asana.com/api/1.0/' . $url_ending;
	$body = json_encode( $data );

	//method is POST for new tasks/projects, PUT for updating existing stuff
	//note that content type has been set to application/json.  That's the only way to get data out of this sucker
	$args = array(
		'access_token'	=> $api_key,
		'method' => $method,
		'body' => $body,
		);

	//call it
	$response = wp_remote_request( $url, $args );
	return $response;
}

// display notice about change to plugin
function asana_settings_notice() {
	if ( current_user_can( 'manage_options' ) ) {
		if ( 'Version 1.0' != get_option( 'show_asana_upgrade_notice' ) ) {
			echo '<div class="updated"><p>';
			printf( __( 'The Asana Task Widget plugin now stores its information in usermeta.  Please go to your profile page to set it up. | <a href="%1$s">Hide Notice</a>' ), '?ignore_asana_settings_notice=0' );
			echo '</p></div>';
		}
	}
}

function ignore_asana_settings_notice() {
	// If user clicks to ignore the notice, update the option
	if ( isset($_GET['ignore_asana_settings_notice']) && '0' == $_GET['ignore_asana_settings_notice'] ) {
		update_option( 'show_asana_upgrade_notice', 'Version 1.0' );
	}
}
