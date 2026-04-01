#!/usr/bin/env bash

set -euo pipefail

ROOT="/Applications/XAMPP/xamppfiles/htdocs/UNDP/undp_platform"
HTML="$ROOT/docs/UNDP_Deployment_Report.html"
DOCX="$ROOT/docs/UNDP_Deployment_Report.docx"

cd "$ROOT"

textutil -convert docx -output "$DOCX" "$HTML"
php .tmp/render_deployment_report_pdf.php

echo "Built:"
echo " - $DOCX"
echo " - $ROOT/docs/UNDP_Deployment_Report.pdf"
