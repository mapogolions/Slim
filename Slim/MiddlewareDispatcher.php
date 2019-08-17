<?php
/**
 * Slim Framework (https://slimframework.com)
 *
 * @license https://github.com/slimphp/Slim/blob/4.x/LICENSE.md (MIT License)
 */

declare(strict_types=1);

namespace Slim;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Interfaces\AdvancedCallableResolverInterface;
use Slim\Interfaces\CallableResolverInterface;

class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * Tip of the middleware call stack
     *
     * @var RequestHandlerInterface
     */
    protected $tip;

    /**
     * @var CallableResolverInterface
     */
    protected $callableResolver;

    /**
     * @var ContainerInterface|null
     */
    protected $container;

    /**
     * @param RequestHandlerInterface   $kernel
     * @param CallableResolverInterface $callableResolver
     * @param ContainerInterface|null   $container
     */
    public function __construct(
        RequestHandlerInterface $kernel,
        CallableResolverInterface $callableResolver,
        ?ContainerInterface $container = null
    ) {
        $this->seedMiddlewareStack($kernel);
        $this->callableResolver = $callableResolver;
        $this->container = $container;
    }

    /**
     * Seed the middleware stack with the inner request handler
     *
     * @param RequestHandlerInterface $kernel
     * @return void
     */
    protected function seedMiddlewareStack(RequestHandlerInterface $kernel): void
    {
        $this->tip = $kernel;
    }

    /**
     * Invoke the middleware stack
     *
     * @param  ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->tip->handle($request);
    }

    /**
     * Add a new middleware to the stack
     *
     * Middleware are organized as a stack. That means middleware
     * that have been added before will be executed after the newly
     * added one (last in, first out).
     *
     * @param MiddlewareInterface|string|callable $middleware
     * @return self
     */
    public function add($middleware): self
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $this->addMiddleware($middleware);
        }

        if (is_string($middleware)) {
            return $this->addDeferred($middleware);
        }

        if (is_callable($middleware)) {
            return $this->addCallable($middleware);
        }

        throw new RuntimeException(
            'A middleware must be an object/class name referencing an implementation of ' .
            'MiddlewareInterface or a callable with a matching signature.'
        );
    }

    /**
     * Add a new middleware to the stack
     *
     * Middleware are organized as a stack. That means middleware
     * that have been added before will be executed after the newly
     * added one (last in, first out).
     *
     * @param MiddlewareInterface $middleware
     * @return self
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $next = $this->tip;
        $this->tip = new class($middleware, $next) implements RequestHandlerInterface
        {
            private $middleware;
            private $next;

            public function __construct(MiddlewareInterface $middleware, RequestHandlerInterface $next)
            {
                $this->middleware = $middleware;
                $this->next = $next;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };

        return $this;
    }

    /**
     * Add a new middleware by class name
     *
     * Middleware are organized as a stack. That means middleware
     * that have been added before will be executed after the newly
     * added one (last in, first out).
     *
     * @param string $middleware
     * @return self
     */
    public function addDeferred(string $middleware): self
    {
        $next = $this->tip;
        $this->tip = new class($middleware, $next, $this->callableResolver) implements RequestHandlerInterface
        {
            private $middleware;
            private $next;
            private $callableResolver;

            public function __construct(
                $middleware,
                RequestHandlerInterface $next,
                CallableResolverInterface $callableResolver
            ) {
                $this->middleware = new DeferredCallable($middleware, $callableResolver);
                $this->next = $next;
                $this->callableResolver = $callableResolver;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->middleware)($request, $this->next);
            }
        };

        return $this;
    }

    /**
     * Add a (non standard) callable middleware to the stack
     *
     * Middleware are organized as a stack. That means middleware
     * that have been added before will be executed after the newly
     * added one (last in, first out).
     *
     * @param callable $middleware
     * @return self
     */
    public function addCallable(callable $middleware): self
    {
        $next = $this->tip;

        if ($this->container && $middleware instanceof Closure) {
            $middleware = $middleware->bindTo($this->container);
        }

        $this->tip = new class($middleware, $next) implements RequestHandlerInterface
        {
            private $middleware;
            private $next;

            public function __construct(callable $middleware, RequestHandlerInterface $next)
            {
                $this->middleware = $middleware;
                $this->next = $next;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->middleware)($request, $this->next);
            }
        };

        return $this;
    }
}
