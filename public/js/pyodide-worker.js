/*
 * Classic Web Worker that runs participant Python code with Pyodide.
 * Kept outside the bundler so it can call importScripts(); the index URL is
 * sent by the page so a locally hosted Pyodide copy can be used offline.
 */
let pyodideReady = null;

const HARNESS = `
import sys, io, json, traceback

def _fmt(exc):
    tb = exc.__traceback__
    while tb is not None and tb.tb_frame.f_code.co_filename != "<kod>":
        tb = tb.tb_next
    return "".join(traceback.format_exception(type(exc), exc, tb)).strip()

def __lab_run(src, tests_json):
    buffer = io.StringIO()
    saved = sys.stdout, sys.stderr
    sys.stdout = sys.stderr = buffer
    sys.setrecursionlimit(400)
    ns = {"__name__": "__main__"}
    result = {"error": None, "results": []}
    try:
        try:
            exec(compile(src, "<kod>", "exec"), ns)
        except BaseException as exc:
            result["error"] = _fmt(exc)
        else:
            for test in json.loads(tests_json):
                row = {"call": test["call"], "expected": test["expected"], "hidden": bool(test.get("hidden"))}
                try:
                    got = eval(test["call"], ns)
                    expected = eval(test["expected"], {})
                    row["ok"] = got == expected
                    row["got"] = repr(got)
                except BaseException as exc:
                    row["ok"] = False
                    row["error"] = _fmt(exc).splitlines()[-1]
                result["results"].append(row)
    finally:
        sys.stdout, sys.stderr = saved
    result["output"] = buffer.getvalue()[:5000]
    return json.dumps(result)
`;

async function boot(indexURL) {
    importScripts(`${indexURL}pyodide.js`);
    const pyodide = await loadPyodide({ indexURL });
    pyodide.runPython(HARNESS);

    return pyodide;
}

self.onmessage = async (event) => {
    const { id, type, indexURL, code, tests } = event.data;

    try {
        pyodideReady ??= boot(indexURL);
        const pyodide = await pyodideReady;

        if (type === 'warmup') {
            self.postMessage({ id, ok: true });

            return;
        }

        const run = pyodide.globals.get('__lab_run');
        const raw = run(code, JSON.stringify(tests || []));
        run.destroy();

        self.postMessage({ id, ok: true, result: JSON.parse(raw) });
    } catch (error) {
        self.postMessage({ id, ok: false, error: String(error?.message || error) });
    }
};
