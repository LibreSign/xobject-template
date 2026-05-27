#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreSign
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

composer lint
composer run test:unit
composer run deps:audit
