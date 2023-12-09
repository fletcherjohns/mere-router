<?php

namespace MereRouter;

use Closure;

/**
 * Requires PHP 7.4
 *
 * Assumes implementation of set of integers to define privilege level of user where 0 represents the lowest available
 * privilege level. This is to be assigned to $_SESSION['privilege'], otherwise privilege == 0.
 */
class MereRouter {
    /*
     * Constants for use in defined routes. Either use as they are, like MereRouter::GET, or
     * copy into the file where you define your route array, so you can just write GET instead.
     */
    const GET = 'GET';
    const POST = 'POST';
    const PUT = 'PUT';
    const PATCH = 'PATCH';
    const DELETE = 'DELETE';
    const TRACE = 'TRACE';
    const OPTIONS = 'OPTIONS';
    const HEAD = 'HEAD';

    const PAGE = 'page';
    const METHOD = 'method';
    const PRIVILEGE = 'privilege';
    const REDIRECT = 'redirect';
    const ROUTES = 'routes';

    private static int $defaultPrivilege = 0;
    private static string $defaultRedirect = '';
    private static array $routes = [];

    private static string $prodHost;
    private static string $prodRedirect;
    private static Closure $prodCondition;

    /**
     * Pass array in the form:
     *
     * @param array $routes
     * @return void
     */
    static function registerRoutes(array $routes): void {
        self::$routes = array_merge_recursive(self::$routes, $routes);
    }

    static function setDefaultPrivilegeGet(int $defaultPrivilege): void {
        self::$defaultPrivilege = $defaultPrivilege;
    }


    static function setDefaultRedirect(string $defaultRedirect): void {
        self::$defaultRedirect = $defaultRedirect;
    }

    /**
     * This optional method allows redirection on production site during development. $prodRedirectCondition must
     * return bool. If true is returned, and HTTP_HOST == $prodDomain then $prodRedirect will be executed.
     *
     * Note that HTTP_HOST will be stripped of www. so define $prodDomain without it.
     *
     * @param string $prodHost
     * @param string $prodRedirect
     * @param Closure(bool): bool $prodCondition
     * @return void
     */
    static function setProdDomainRedirect(string $prodHost, string $prodRedirect, Closure $prodCondition): void {
        self::$prodHost = $prodHost;
        self::$prodRedirect = $prodRedirect;
        self::$prodCondition = $prodCondition;
    }

    /**
     * $privilege may be a Closure or an integer representing privilege level.
     * If it is a Closure, the closure must return a boolean to determine access.
     * If it is an integer, $_SESSION[self::PRIVILEGE] must be greater or equal to gain access.
     * If $_SESSION[self::PRIVILEGE] is not set, it defaults to 0.
     * @param $privilege
     * @return bool
     */
    private static function satisfyPrivilege($privilege): bool {

        return $privilege instanceof Closure ?
            $privilege() : ($_SESSION[self::PRIVILEGE] ?? 0) >= $privilege;
    }

    public static function processRequest(): string {
        return self::processRequestRecurrently();
    }

    /**
     * Process the current request. Call this function with no parameters to begin process.
     *
     * Function calls itself recursively, splitting REQUEST_URI and beginning at index == 1 of self::$routes.
     * Then, depending on defined routes (if a route has an array of routes of its own) it calls itself, passing
     * those routes and incrementing $index.
     *
     * @param array|null $routes
     * @param int $index
     * @return string
     */
    private static function processRequestRecurrently(array $routes = null, int $index = 1): string {

        // On first call, $routes == null and should be set to self::$routes
        if ($routes == null) {
            $routes = self::$routes;
        }
        // Obtain REQUEST_URI, stripped of parameters, as array, and REQUEST_METHOD
        $path = explode("/", explode("?", $_SERVER['REQUEST_URI'])[0]);
        $request_method = $_SERVER['REQUEST_METHOD'];

        // If $prodDomain has been set, apply the redirect according to the condition.
        if (isset(self::$prodHost)) {

            // String 'www.' from HTTP_HOST before comparison to $prodHost.
            $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST']);
            if ($host == self::$prodHost && self::$prodCondition) {
                header('Location: '.self::$prodRedirect);
                exit();
            }
        }

        // Initialise $privilege and $redirect as default.
        $privilege = self::$defaultPrivilege;
        $redirect =  self::$defaultRedirect;

        // Note that $routes is a nested associative array to be traversed down into.
        // Check if element at $index of $path exists in $routes.
        if (isset($routes[$path[$index]])) {

            // If so, assign associated value to $route.
            $route = $routes[$path[$index]];

            // Check if REQUEST_METHOD exists in $route.
            if (isset($route[$request_method])) {

                // If so, assign associated value to $route.
                $route = $route[$request_method];

                // Update $privilege if set for $route
                if (isset($route[self::PRIVILEGE])) $privilege = $route[self::PRIVILEGE];
                // Check if privilege is satisfied.
                if (self::satisfyPrivilege($privilege)) {

                    // If satisfied, obtain page, method and routes (if they are set).
                    if (isset($route[self::PAGE])) $page = $route[self::PAGE];
                    if (isset($route[self::METHOD])) {
                        $method = $route[self::METHOD];

                        // If $method is a Closure, call it.
                        if ($method instanceof Closure || function_exists($method)) {
                            // If there are elements after this one in the path, send them as parameters to $function
                            $args = array_slice($path, $index + 1);
                            $method(...$args);
                        }
                    }
                    // If routes are set, call this function again, passing those routes and incrementing $index.
                    if (isset($route[self::ROUTES]) && count($route[self::ROUTES]) > 0) {
                        $page = self::processRequestRecurrently($route->routes, $index + 1);
                    }
                } else if (isset($route[self::REDIRECT])) {
                    // Update $redirect if it is set for $route.
                    $redirect = $route[self::REDIRECT];
                }
            }
            // Return $page if it is set and is a valid file.
            if (isset($page) && file_exists($page)) {
                return $page;
            }
        }
        // If we've got to here, no page was returned. Redirect and exit.
        header("Location: /$redirect");
        exit();
    }
}