<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class Plugin
{
    private static ?self $instance = null;

    /** @var list<ServiceProviderInterface> */
    private array $services;

    private bool $registered = false;

    private function __construct()
    {
        $this->services = [
            new AccessGate(),
            new AdminBarDisabled(),
            new CommentsDisabled(),
            new ContentTypes(),
            new RecipeMeta(),
            new RestProtection(),
            new CliCommands(),
        ];
    }

    public static function instance(): self
    {
        if (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        foreach ($this->services as $service) {
            $service->register();
        }

        $this->registered = true;
    }
}
