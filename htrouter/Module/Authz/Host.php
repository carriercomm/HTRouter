<?php
/**
 * Access module
 *
 */

namespace HTRouter\Module\Authz;

class Host Extends \HTRouter\AuthzModule {
    // The different order constants
    const ALLOW_THEN_DENY = 1;
    const DENY_THEN_ALLOW = 2;
    const MUTUAL_FAILURE = 3;

    public function init(\HTRouter $router, \HTRouter\HTDIContainer $container)
    {
        parent::init($router, $container);

        // Register directives
        $router->registerDirective($this, "allow");
        $router->registerDirective($this, "deny");
        $router->registerDirective($this, "order");

        // Register hooks
        $router->registerHook(\HTRouter::HOOK_CHECK_ACCESS, array($this, "checkAccess"));

        // Set default values
        $this->getConfig()->set("AccessOrder", self::DENY_THEN_ALLOW);
        $this->getConfig()->set("AccessDeny", array());
        $this->getConfig()->set("AccessAllow", array());
    }


    public function checkUserAccess(\HTRouter\Request $request)
    {
        // Not needed, we are hooking in check_access
        // @TODO: Then why are we doing this???
    }


    public function allowDirective(\HTRouter\Request $request, $line) {
        if (! preg_match("/^from (.+)$/i", $line, $match)) {
            throw new \InvalidArgumentException("allow must be followed by a 'from'");
        }

        // Convert each item on the line to our custom "entry" object
        foreach ($this->_convertToEntry($match[1]) as $item) {
            $this->getConfig()->append("AccessAllow", $item);
        }
    }

    public function denyDirective(\HTRouter\Request $request, $line) {
        if (! preg_match("/^from (.+)$/i", $line, $match)) {
            throw new \InvalidArgumentException("deny must be followed by a 'from'");
        }

        // Convert each item on the line to our custom "entry" object
        foreach ($this->_convertToEntry($match[1]) as $item) {
            $this->getConfig()->append("AccessDeny", $item);
        }

    }

    public function orderDirective(\HTRouter\Request $request, $line) {
        // Funny.. Apache does a strcmp on "allow,deny", so you can't have "allow, deny" spaces in between.
        // So we shouldn't allow it either.

        $utils = new \HTRouter\Utils;
        $value = $utils->fetchDirectiveFlags($line, array("allow,deny" => self::ALLOW_THEN_DENY,
                                                          "deny,allow" => self::DENY_THEN_ALLOW,
                                                          "mutual-failure" => self::MUTUAL_FAILURE));
        $this->getConfig()->set("AccessOrder", $value);
    }


    /**
     * These functions should return true|false or something to make sure we can continue with our stuff?
     *
     * @param \HTRouter\Request $request
     * @return bool
     * @throws \LogicException
     */
    public function checkAccess(\HTRouter\Request $request) {

        // The way we parse things depends on the "order"
        switch ($this->getConfig()->get("AccessOrder")) {
            case self::ALLOW_THEN_DENY :
                $result = false;
                if ($this->_findAllowDeny($this->getConfig()->get("AccessAllow"))) {
                    $result = \HTRouter::STATUS_OK;
                }
                if ($this->_findAllowDeny($this->getConfig()->get("AccessDeny"))) {
                    $result = \HTRouter::STATUS_HTTP_FORBIDDEN;
                }
                break;
            case self::DENY_THEN_ALLOW :
                $result = \HTRouter::STATUS_OK;
                if ($this->_findAllowDeny($this->getConfig()->get("AccessDeny"))) {
                    $result = \HTRouter::STATUS_HTTP_FORBIDDEN;
                }
                if ($this->_findAllowDeny($this->getConfig()->get("AccessAllow"))) {
                    $result = \HTRouter::STATUS_OK;
                }
                break;
            case self::MUTUAL_FAILURE :
                if ($this->_findAllowDeny($this->getConfig()->get("AccessAllow")) and
                    !$this->_findAllowDeny($this->getConfig()->get("AccessDeny"))) {
                    $result = \HTRouter::STATUS_OK;
                } else {
                    $result = \HTRouter::STATUS_HTTP_FORBIDDEN;
                }
                break;
            default:
                throw new \LogicException("Unknown order");
                break;
        }

        // Not ok. Now we need to check if "satisfy any" already got a satisfaction
        if ($result == \HTRouter::STATUS_HTTP_FORBIDDEN &&
           ($this->getConfig()->get("Satisfy") == "any" || count($this->getConfig()->get("Requires", array()) == 0))) {
            // Check if there is at least one require line in the htaccess. If found, it means that
            // we still have to possibility that we can be authorized
            $this->getLogger()->log(\HTRouter\Logger::ERRORLEVEL_ERROR, "Access denied for ".$request->getFilename()." / ".$request->getUri());
        }

        // Return what we need to return
        return $result;
    }

    protected function _findAllowDeny(array $items) {
        $utils = new \HTRouter\Utils;

        // Iterate all "ALLOW" or "DENY" items. We just return if at least one of them matches
        foreach ($items as $entry) {
            switch ($entry->type) {
                case "env" :
                    $env = $this->getRouter()->getEnvironment();
                    if (isset($env[$entry->env])) return true;
                    break;
                case "nenv" :
                    $env = $this->getRouter()->getEnvironment();
                    if (! isset ($env[$entry->env])) return true;
                    break;
                case "all" :
                    return true;
                    break;
                case "ip" :
                    if ($utils->checkMatchingIP($entry->ip, $this->getRequest()->getIp())) return true;
                    break;
                case "host" :
                    if ($utils->checkMatchingHost($entry->host, $this->getRequest()->getIp())) return true;
                    break;
                default:
                    throw new \LogicException("Unknown entry type: ".$entry->type);
                    break;
            }
        }
        return false;
    }

    /**
     * Convert a line to an array of simple entry objects
     *
     * @param $line
     * @return array
     */
    protected function _convertToEntry($line) {
        $entries = array();

        foreach (explode(" ", $line) as $item) {
            $entry = new \StdClass();

            if ($item == "all") {
                $entry->type = "all";
                $entries[] = $entry;
                continue;
            }

            // Must be parsed BEFORE env= is parsed!
            if (substr($item, 0, 5) === "env=!") {
                $entry->type = "nenv";
                $entry->env = substr($item, 5);
                $entries[] = $entry;
                continue;
            }

            if (substr($item, 0, 4) === "env=") {
                $entry->type = "env";
                $entry->env = substr($item, 4);
                $entries[] = $entry;
                continue;
            }

            if (strchr($item, "/")) {
                // IP with subnet mask or cidr
                $entry->type = "ip";
                $entry->ip = $line;
                $entries[] = $entry;
                continue;
            }
            if (preg_match("/^[\d\.]+$/", $line)) {
                // Looks like it's an IP or partial IP
                $entry->type = "ip";
                $entry->ip = $line;
                $entries[] = $entry;
                continue;
            }

            // Nothing found, treat as (partial) hostname
            $entry->type = "host";
            $entry->host = $line;
            $entries[] = $entry;
        }

        return $entries;
    }


    public function getAliases() {
        return array("mod_authz_host.c", "authz_host");
    }

}