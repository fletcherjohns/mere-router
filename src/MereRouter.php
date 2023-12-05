<?php

namespace MereRouter;

const PRIVILEGE = 'privilege'; // This is already defined in UserManager
const DEFAULT_PRIVILEGE_GET = 'default_privilege_get';
const DEFAULT_PRIVILEGE_POST = 'default_privilege_post';
const DEFAULT_REDIRECT = 'default_redirect';

const GET = 'GET';
const HEAD = 'HEAD';
const POST = 'POST';
const PUT = 'PUT';
const DELETE = 'DELETE';

const METHOD = 'method';
const PAGE = 'page';
const REDIRECT = 'redirect';
const ROUTES = 'routes';

/**
 * Dependencies none, but works with $_SESSION[PRIVILEGE] set by UserManager
 * routes.php file should be present in the root folder. This file should define a PHP array like this:
    define("DEFINED_ROUTES", [

    DEFAULT_PRIVILEGE_GET => GUEST,
    DEFAULT_PRIVILEGE_POST => MEMBER,
    DEFAULT_REDIRECT => 'home',

    '' => [
        GET => [
            FUNCTION => function() {
                // optional closure, custom privilege with redirect? run a script?
            },
            PAGE => 'pages/home.php',
            PRIVILEGE => GUEST, // minimum privilege to be granted access
            REDIRECT => 'home', // where to redirect to if privilege check fails
        ]
    ]
 *
 */
class MereRouter {

    private static array $routes = [];

    static function defineRoutes(array $routes): void {
        self::$routes = $routes;
    }

    /**
     * $privilege may be a Closure or an integer representing privilege level.
     * If it is a Closure, the closure must return a boolean to determine access.
     * If it is an integer, $_SESSION[PRIVILEGE] must be greater or equal to gain access.
     * @param $privilege
     * @return bool
     */
    static function satisfyPrivilege($privilege): bool {
        return $privilege instanceof \Closure ?
            $privilege() :
            $_SESSION[PRIVILEGE] >= $privilege;
    }

    static function processRequest($routes = null, $index = 1) {

        $path = explode("/", explode("?", $_SERVER['REQUEST_URI'])[0]);

        if(($_SERVER['HTTP_HOST'] !== "127.0.0.1" && $_SERVER['HTTP_HOST'] !== 'meremammal.test')
            && !isset($_SESSION['user']) && $path[1] != 'login') {
            //TODO Maybe remove later. This is just for testing purposes.
            header('Location: /login');
            exit();
        }
        $redirect =  $routes[DEFAULT_REDIRECT] ?? '';
        if (isset(self::$routes[$path[$index]])) {

            $route = self::$routes[$path[$index]];
            $request_method = $_SERVER['REQUEST_METHOD'];

            if (isset($route[$request_method])) {
                $route = $route[$request_method];
            }

            if (!isset($route[PRIVILEGE]) || self::satisfyPrivilege($route[PRIVILEGE])) {

                if (isset($route[METHOD])) $function = $route[METHOD];
                if (isset($route[PAGE])) $include = $route[PAGE];
                if (isset($route[ROUTES]) && count($route[ROUTES]) > 0) {
                    $include = self::processRequest($route->routes, $index + 1);
                }
            } else {
                $redirect = $route[REDIRECT];
                header("Location: /$redirect");
                exit();

            }
            if (isset($function) && ($function instanceof \Closure || function_exists($function))) {
                // If there are elements after this one in the path, send them as parameters to $function
                $args = array_slice($path, $index + 1);
                $function(...$args);
            }
            if (isset($include) && file_exists($include)) {
                return $include;
            }
        } else if ($path[$index] == '') {
            return 'pages/home.php';
        }
        header("Location: /$redirect");
        exit();
    }
}