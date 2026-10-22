"""End-to-end local Graphify MCP stdio handshake and read-only graph query."""
import asyncio
import os
from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client

async def main():
    folder = os.path.join(os.path.expanduser("~"), "Graphify", "unrealdb", "graphify-out")
    graph = os.path.join(folder, "graph.json")
    executable = os.path.join(os.path.expanduser("~"), ".local", "bin", "graphify-mcp.exe")
    if not os.path.isfile(graph) or not os.path.isfile(executable):
        raise RuntimeError("Missing graph or Graphify MCP executable")
    params = StdioServerParameters(command=executable, args=["--graph", graph])
    async with stdio_client(params) as (reader, writer):
        async with ClientSession(reader, writer) as session:
            result = await session.initialize()
            listing = await session.list_tools()
            names = [t.name for t in listing.tools]
            print("MCP_INITIALIZED", result.server_info.name, result.server_info.version)
            print("MCP_TOOLS", len(names), ", ".join(names))
            tool = "graph_stats"
            if tool not in names:
                raise RuntimeError(f"Expected read-only graph_stats tool missing: {names}")
            answer = await session.call_tool(tool, {})
            print("MCP_GRAPH_STATS", str(answer.content)[:3000])
            if getattr(answer, "isError", False):
                raise RuntimeError("Graphify MCP tool reported an error")
    print("MCP_STDIO_SMOKE_PASS")

asyncio.run(main())
