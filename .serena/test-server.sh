#!/bin/bash
timeout 5 uvx --from git+https://github.com/oraios/serena serena start-mcp-server \
  --context cli \
  --project-path /Users/Benson/Code/AlkaidSYS-tp \
  2>&1 | head -20
