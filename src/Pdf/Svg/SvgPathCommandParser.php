<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use InvalidArgumentException;

/**
 * Parses SVG path data and converts it to PDF path command strings.
 *
 * Handles all SVG path commands (M, L, H, V, C, S, Q, T, A, Z) and converts
 * them to equivalent PDF drawing commands in the PDF coordinate system.
 */
final readonly class SvgPathCommandParser
{
    public function __construct(
        private SvgArcConverter $arcConverter = new SvgArcConverter(),
        private SvgTransformResolver $transformResolver = new SvgTransformResolver(),
        private SvgPathNumberReader $pathNumberReader = new SvgPathNumberReader(),
    ) {
    }

    /**
     * Convert an SVG path `d` attribute value to PDF path commands.
     *
     * @param string $pathData   The SVG path data string
     * @param float  $minX       The viewBox X origin
     * @param float  $maxY       The viewBox bottom Y (for flipping Y axis)
     * @param string $source     Source identifier for error messages
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix Cumulative transform matrix
     * @return string PDF path command sequence
     */
    public function convertPathData(
        string $pathData,
        float $minX,
        float $maxY,
        string $source,
        array $transformMatrix,
    ): string {
        $tokens = $this->tokenizePathData($pathData, $source);
        $state = new PathParsingState();
        $context = new PathCommandContext($transformMatrix, $minX, $maxY, $source);
        $this->processPathTokens($tokens, $state, $context);
        return implode("\n", $state->commands);
    }

    /**
     * Tokenize SVG path data string into command and number tokens.
     *
     * @param string $pathData The SVG path data string
     * @param string $source   Source identifier for error messages
     * @return list<string> Array of tokens
     */
    private function tokenizePathData(string $pathData, string $source): array
    {
        preg_match_all(
            '/([A-Za-z])|([-+]?\d*\.?\d+(?:[eE][-+]?\d+)?)/',
            $pathData,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === []) {
            throw new InvalidArgumentException(sprintf('Unsupported or empty SVG path data in "%s".', $source));
        }

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = $match[1] !== '' ? $match[1] : $match[2];
        }

        return $tokens;
    }

    /**
     * Process tokenized SVG path data and generate PDF commands.
     *
     * @param list<string> $tokens
     */
    private function processPathTokens(array $tokens, PathParsingState $state, PathCommandContext $context): void
    {
        $tokenCount = count($tokens);
        $index = 0;
        $currentCommand = null;

        while ($index < $tokenCount) {
            $token = $tokens[$index];
            if (preg_match('/^[A-Za-z]$/', $token) === 1) {
                $currentCommand = $token;
                ++$index;
            }

            if ($currentCommand === null) {
                throw new InvalidArgumentException(sprintf('Invalid SVG path command sequence in "%s".', $context->source));
            }

            $isRelative = ctype_lower($currentCommand);
            $command = strtoupper($currentCommand);

            $this->handlePathCommand(
                $command,
                $isRelative,
                $tokens,
                $index,
                $tokenCount,
                $state,
                $context,
            );
        }
    }

    /**
     * Route path command to appropriate handler.
     *
     * @param list<string> $tokens
     */
    private function handlePathCommand(
        string $command,
        bool $isRelative,
        array $tokens,
        int &$index,
        int $tokenCount,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        match ($command) {
            'M' => $this->handleMoveCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'L' => $this->handleLineCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'H' => $this->handleHorizontalCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'V' => $this->handleVerticalCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'C' => $this->handleCubicCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'S' => $this->handleSmoothCubicCommand(
                $tokens,
                $index,
                $tokenCount,
                $isRelative,
                $state,
                $context,
            ),
            'Q' => $this->handleQuadraticCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'T' => $this->handleSmoothQuadraticCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'A' => $this->handleArcCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'Z' => $this->handleClosePathCommand($state),
            default => throw new InvalidArgumentException(sprintf(
                'SVG path command "%s" is not supported for source "%s".',
                $command,
                $context->source,
            )),
        };
    }

    /**
     * @param list<string> $tokens
     */
    private function handleMoveCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 2, $context->source);
        $state->currentX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
        $state->currentY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
        [$moveX, $moveY] = $this->transformResolver->applyTransformToPoint(
            $context->transformMatrix,
            $state->currentX,
            $state->currentY,
        );
        $state->commands[] = sprintf('%F %F m', $moveX - $context->minX, $context->maxY - $moveY);
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;

        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 2, $context->source);
            $nextX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $nextY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $this->appendLineToState($state, $context, $nextX, $nextY);
        }
        $state->prevQuadCpX = null;
        $state->prevQuadCpY = null;
    }

    /**
     * @param list<string> $tokens
     */
    private function handleLineCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 2, $context->source);
            $nextX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $nextY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $this->appendLineToState($state, $context, $nextX, $nextY);
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleHorizontalCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 1, $context->source);
            $state->currentX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            [$lineX, $lineY] = $this->transformResolver->applyTransformToPoint(
                $context->transformMatrix,
                $state->currentX,
                $state->currentY,
            );
            $state->commands[] = sprintf('%F %F l', $lineX - $context->minX, $context->maxY - $lineY);
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleVerticalCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 1, $context->source);
            $state->currentY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[0]);
            [$lineX, $lineY] = $this->transformResolver->applyTransformToPoint(
                $context->transformMatrix,
                $state->currentX,
                $state->currentY,
            );
            $state->commands[] = sprintf('%F %F l', $lineX - $context->minX, $context->maxY - $lineY);
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleCubicCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 6, $context->source);
            $startX1 = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $startY1 = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $endX2 = $this->resolveCoord($isRelative, $state->currentX, $coordinates[2]);
            $endY2 = $this->resolveCoord($isRelative, $state->currentY, $coordinates[3]);
            $x = $this->resolveCoord($isRelative, $state->currentX, $coordinates[4]);
            $y = $this->resolveCoord($isRelative, $state->currentY, $coordinates[5]);

            $state->commands[] = $this->buildCubicCurveCommand(
                $context->transformMatrix,
                $context->minX,
                $context->maxY,
                $startX1,
                $startY1,
                $endX2,
                $endY2,
                $x,
                $y,
            );
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = $endX2;
            $state->lastCubicControlY = $endY2;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSmoothCubicCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 4, $context->source);
            $startX1 = $this->reflectControlPoint($state->lastCubicControlX, $state->currentX);
            $startY1 = $this->reflectControlPoint($state->lastCubicControlY, $state->currentY);
            $endX2 = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $endY2 = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $x = $this->resolveCoord($isRelative, $state->currentX, $coordinates[2]);
            $y = $this->resolveCoord($isRelative, $state->currentY, $coordinates[3]);

            $state->commands[] = $this->buildCubicCurveCommand(
                $context->transformMatrix,
                $context->minX,
                $context->maxY,
                $startX1,
                $startY1,
                $endX2,
                $endY2,
                $x,
                $y,
            );
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = $endX2;
            $state->lastCubicControlY = $endY2;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleQuadraticCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 4, $context->source);
                $qcpX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
                $qcpY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
                $x    = $this->resolveCoord($isRelative, $state->currentX, $coordinates[2]);
                $y    = $this->resolveCoord($isRelative, $state->currentY, $coordinates[3]);

            [$controlX1, $controlY1, $controlX2, $controlY2] = $this->quadraticToCubicControlPoints(
                $state->currentX,
                $state->currentY,
                $qcpX,
                $qcpY,
                $x,
                $y,
            );

            $this->appendQuadraticAsCubicToState($state, $context, $controlX1, $controlY1, $controlX2, $controlY2, $x, $y);
            $state->prevQuadCpX = $qcpX;
            $state->prevQuadCpY = $qcpY;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSmoothQuadraticCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 2, $context->source);
            $qcpX = $this->reflectControlPoint($state->prevQuadCpX, $state->currentX);
            $qcpY = $this->reflectControlPoint($state->prevQuadCpY, $state->currentY);
            $x = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $y = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            [$controlX1, $controlY1, $controlX2, $controlY2] = $this->quadraticToCubicControlPoints(
                $state->currentX,
                $state->currentY,
                $qcpX,
                $qcpY,
                $x,
                $y,
            );

            $this->appendQuadraticAsCubicToState($state, $context, $controlX1, $controlY1, $controlX2, $controlY2, $x, $y);
            $state->prevQuadCpX = $qcpX;
            $state->prevQuadCpY = $qcpY;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleArcCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->pathNumberReader->readPathNumbers($tokens, $index, 7, $context->source);
            $radiusX       = abs($coordinates[0]);
            $radiusY       = abs($coordinates[1]);
            $rotation = $coordinates[2];
            $largeArc = (int) $coordinates[3];
            $sweep    = (int) $coordinates[4];
            $x        = $this->resolveCoord($isRelative, $state->currentX, $coordinates[5]);
            $y        = $this->resolveCoord($isRelative, $state->currentY, $coordinates[6]);

            $curves = $this->arcConverter->arcToBezierCurves(
                $state->currentX,
                $state->currentY,
                $radiusX,
                $radiusY,
                $rotation,
                $largeArc,
                $sweep,
                $x,
                $y,
            );

            foreach ($curves as $curve) {
                [$controlPoint1X, $controlPoint1Y] = $this->transformResolver->applyTransformToPoint(
                    $context->transformMatrix,
                    $curve[0],
                    $curve[1],
                );
                [$controlPoint2X, $controlPoint2Y] = $this->transformResolver->applyTransformToPoint(
                    $context->transformMatrix,
                    $curve[2],
                    $curve[3],
                );
                [$endX, $endY] = $this->transformResolver->applyTransformToPoint(
                    $context->transformMatrix,
                    $curve[4],
                    $curve[5],
                );
                $state->commands[] = sprintf(
                    '%F %F %F %F %F %F c',
                    $controlPoint1X - $context->minX,
                    $context->maxY - $controlPoint1Y,
                    $controlPoint2X - $context->minX,
                    $context->maxY - $controlPoint2Y,
                    $endX - $context->minX,
                    $context->maxY - $endY,
                );
            }

            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    private function handleClosePathCommand(PathParsingState $state): void
    {
        $state->commands[] = 'h';
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;
    }

    /**
     * Resolve a coordinate as absolute or relative to current position.
     */
    private function resolveCoord(bool $isRelative, float $current, float $coord): float
    {
        return $isRelative ? $current + $coord : $coord;
    }

    /**
     * Reflect a previous control point over the current position.
     * Returns current position when no prior control point exists (SVG spec default).
     */
    private function reflectControlPoint(?float $prevControl, float $current): float
    {
        return $prevControl === null ? $current : (2.0 * $current) - $prevControl;
    }

    private function appendLineToState(
        PathParsingState $state,
        PathCommandContext $context,
        float $toX,
        float $toY,
    ): void {
        $state->currentX = $toX;
        $state->currentY = $toY;
        [$transformedX, $transformedY] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $toX, $toY);
        $state->commands[] = sprintf('%F %F l', $transformedX - $context->minX, $context->maxY - $transformedY);
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;
    }

    private function appendQuadraticAsCubicToState(
        PathParsingState $state,
        PathCommandContext $context,
        float $controlX1,
        float $controlY1,
        float $controlX2,
        float $controlY2,
        float $x,
        float $y,
    ): void {
        $state->commands[] = $this->buildCubicCurveCommand(
            $context->transformMatrix,
            $context->minX,
            $context->maxY,
            $controlX1,
            $controlY1,
            $controlX2,
            $controlY2,
            $x,
            $y,
        );
        $state->currentX = $x;
        $state->currentY = $y;
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;
    }

    /**
     * @return array{0:float,1:float,2:float,3:float} [$controlX1, $controlY1, $controlX2, $controlY2]
     */
    private function quadraticToCubicControlPoints(
        float $fromX,
        float $fromY,
        float $qcpX,
        float $qcpY,
        float $toX,
        float $toY,
    ): array {
        $controlX1 = $fromX + (2.0 / 3.0) * ($qcpX - $fromX);
        $controlY1 = $fromY + (2.0 / 3.0) * ($qcpY - $fromY);
        $controlX2 = $toX + (2.0 / 3.0) * ($qcpX - $toX);
        $controlY2 = $toY + (2.0 / 3.0) * ($qcpY - $toY);

        return [$controlX1, $controlY1, $controlX2, $controlY2];
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildCubicCurveCommand(
        array $transformMatrix,
        float $minX,
        float $maxY,
        float $startX1,
        float $startY1,
        float $endX2,
        float $endY2,
        float $x,
        float $y,
    ): string {
        [$transformX1, $transformY1] = $this->transformResolver->applyTransformToPoint($transformMatrix, $startX1, $startY1);
        [$transformX2, $transformY2] = $this->transformResolver->applyTransformToPoint($transformMatrix, $endX2, $endY2);
        [$transformX, $transformY] = $this->transformResolver->applyTransformToPoint($transformMatrix, $x, $y);

        return sprintf(
            '%F %F %F %F %F %F c',
            $transformX1 - $minX,
            $maxY - $transformY1,
            $transformX2 - $minX,
            $maxY - $transformY2,
            $transformX - $minX,
            $maxY - $transformY,
        );
    }
}
