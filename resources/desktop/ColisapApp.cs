// COLISAP desktop app: a Windows program with its own window that shows the COLISAP system from the
// cooperative's server through WebView2, the display engine built into Windows 10/11.
// Every PC connects to the same server, so all staff share one database and see every update immediately.
// The exe also updates itself from <server>/downloads/ when a newer version is published there.
// Build with resources/desktop/build.ps1 (uses the C# compiler that ships with Windows; nothing to install).

using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Reflection;
using System.Runtime.CompilerServices;
using System.Runtime.InteropServices;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

[assembly: AssemblyTitle("COLISAP")]
[assembly: AssemblyProduct("COLISAP Monitoring System")]
[assembly: AssemblyCompany("Barbaza Multi-Purpose Cooperative")]
[assembly: AssemblyDescription("COLISAP monitoring system desktop app")]
[assembly: AssemblyVersion(Program.Version)]
[assembly: AssemblyFileVersion(Program.Version)]

internal static class Program
{
    /// <summary>Raise this for every new build: installed apps update themselves when the server has a higher number.</summary>
    internal const string Version = "1.2.0.0";

    internal const string AppTitle = "COLISAP — Barbaza MPC";

    /// <summary>The cooperative's COLISAP server (hosted on Dokploy). Staff never type it; Shift at launch changes it.</summary>
    internal const string DefaultServer = "https://colisap.barbazampc.cloud";

    internal static readonly Color Indigo = Color.FromArgb(46, 58, 140);

    [DllImport("user32.dll")]
    private static extern bool SetProcessDPIAware();

    [STAThread]
    private static void Main(string[] args)
    {
        // The WebView2 libraries travel inside this exe; hand them over before any code needs them.
        AppDomain.CurrentDomain.AssemblyResolve += EmbeddedLibraries.Resolve;

        try { SetProcessDPIAware(); } catch (Exception) { }

        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072; // TLS 1.2 for an https:// server

        Updater.FinishPendingUpdate();

        // Connects to the built-in server unless another address was chosen. Hold Shift while opening,
        // or run "COLISAP.exe /setup", to choose a different server (e.g. the office PC for testing).
        bool wantsSetup = (Control.ModifierKeys & Keys.Shift) == Keys.Shift || Array.Exists(args, a => a.Equals("/setup", StringComparison.OrdinalIgnoreCase));
        string server = wantsSetup ? null : (Settings.SavedServer() ?? Settings.ServerFromIni() ?? DefaultServer);

        if (string.IsNullOrEmpty(server))
        {
            server = ServerDialog.Ask(Settings.SavedServer() ?? Settings.ServerFromIni() ?? DefaultServer);

            if (server == null)
            {
                return;
            }

            Settings.Save(server);
        }

        Run(server);
    }

    /// <summary>Kept apart from Main so WebView2 is only loaded after the embedded-library handler is in place.</summary>
    [MethodImpl(MethodImplOptions.NoInlining)]
    private static void Run(string server)
    {
        string loaderFolder = EmbeddedLibraries.ExtractLoader();

        try
        {
            CoreWebView2Environment.SetLoaderDllFolderPath(loaderFolder);
            CoreWebView2Environment.GetAvailableBrowserVersionString();
        }
        catch (Exception)
        {
            DialogResult choice = ChoiceDialog.Show(
                "One Windows component is missing",
                "COLISAP needs Microsoft WebView2, which is part of Windows 10 and 11 but is missing on this computer.\n\nInstall it (free, from Microsoft) and open COLISAP again, or open COLISAP in the web browser for now.",
                "Install WebView2", "Open in browser", "Close");

            if (choice == DialogResult.Retry)
            {
                Process.Start("https://go.microsoft.com/fwlink/p/?LinkId=2124703");
            }
            else if (choice == DialogResult.Ignore)
            {
                Process.Start(server.TrimEnd('/') + "/admin");
            }

            return;
        }

        Application.Run(new MainWindow(server));
    }

    internal static Form NewDialog(string title, int width, int height)
    {
        Form form = new Form();
        form.Text = title;
        form.FormBorderStyle = FormBorderStyle.FixedDialog;
        form.MaximizeBox = false;
        form.MinimizeBox = false;
        form.StartPosition = FormStartPosition.CenterScreen;
        form.ClientSize = new Size(width, height);
        form.Font = new Font("Segoe UI", 10f);
        form.BackColor = Color.White;
        form.Icon = AppIcon();

        Panel band = new Panel();
        band.Dock = DockStyle.Top;
        band.Height = 6;
        band.BackColor = Indigo;
        form.Controls.Add(band);

        return form;
    }

    internal static Icon AppIcon()
    {
        try { return Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch (Exception) { return null; }
    }

    internal static Button NewButton(string text, int left, int top, bool primary)
    {
        Button button = new Button();
        button.Text = text;
        button.SetBounds(left, top, 140, 34);
        button.FlatStyle = FlatStyle.Flat;
        button.BackColor = primary ? Indigo : Color.FromArgb(241, 243, 249);
        button.ForeColor = primary ? Color.White : Color.FromArgb(20, 26, 58);
        button.FlatAppearance.BorderSize = primary ? 0 : 1;
        button.FlatAppearance.BorderColor = Color.FromArgb(203, 209, 226);
        return button;
    }

    internal static string LocalFolder(string name)
    {
        string folder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "COLISAP\\" + name);
        Directory.CreateDirectory(folder);
        return folder;
    }
}

/// <summary>The app's main window: the COLISAP system, its own title bar, taskbar icon and remembered size.</summary>
internal sealed class MainWindow : Form
{
    private readonly string server;
    private readonly Uri serverUri;
    private readonly WebView2 view = new WebView2();
    private CoreWebView2Environment environment;
    private bool updateChecked;

    public MainWindow(string server)
    {
        this.server = server.TrimEnd('/');
        this.serverUri = new Uri(this.server);

        Text = Program.AppTitle;
        Icon = Program.AppIcon();
        BackColor = Color.White;
        MinimumSize = new Size(900, 600);
        WindowPlacement.Restore(this);

        view.Dock = DockStyle.Fill;
        view.DefaultBackgroundColor = Color.White;
        Controls.Add(view);

        Load += async delegate { await Start(); };
        FormClosing += delegate { WindowPlacement.Save(this); };
        FormClosed += delegate { Updater.ApplyOnExit(); };
    }

    private async Task Start()
    {
        try
        {
            environment = await CoreWebView2Environment.CreateAsync(null, Program.LocalFolder("WebView2"));
            await view.EnsureCoreWebView2Async(environment);
        }
        catch (Exception exception)
        {
            MessageBox.Show(this, "COLISAP could not start its display engine.\n\n" + exception.Message, Program.AppTitle, MessageBoxButtons.OK, MessageBoxIcon.Error);
            Close();
            return;
        }

        CoreWebView2 core = view.CoreWebView2;
        Configure(core);
        core.DocumentTitleChanged += delegate { Text = string.IsNullOrEmpty(core.DocumentTitle) ? Program.AppTitle : core.DocumentTitle; };
        core.NavigationCompleted += OnNavigationCompleted;
        core.NewWindowRequested += OnNewWindowRequested;
        core.WebMessageReceived += OnWebMessage;

        core.Navigate(server + "/admin");
    }

    internal void Configure(CoreWebView2 core)
    {
        core.Settings.AreDevToolsEnabled = false;
        core.Settings.IsStatusBarEnabled = false;
        core.Settings.UserAgent = core.Settings.UserAgent + " COLISAP-Desktop/" + Program.Version;
        core.NavigationStarting += OnNavigationStarting;
    }

    private bool IsServerAddress(string address)
    {
        Uri uri;

        if (!Uri.TryCreate(address, UriKind.Absolute, out uri))
        {
            return false;
        }

        return uri.Scheme == "data" || uri.Scheme == "about" || uri.Scheme == "blob"
            || (uri.Host.Equals(serverUri.Host, StringComparison.OrdinalIgnoreCase) && uri.Port == serverUri.Port);
    }

    /// <summary>Links to other websites open in the normal web browser, never inside COLISAP.</summary>
    private void OnNavigationStarting(object sender, CoreWebView2NavigationStartingEventArgs e)
    {
        if (!IsServerAddress(e.Uri))
        {
            e.Cancel = true;
            OpenInBrowser(e.Uri);
        }
    }

    private async void OnNewWindowRequested(object sender, CoreWebView2NewWindowRequestedEventArgs e)
    {
        if (!IsServerAddress(e.Uri))
        {
            e.Handled = true;
            OpenInBrowser(e.Uri);
            return;
        }

        // Pages the system opens in a new tab (certificates, letters, PDFs) get their own COLISAP window.
        CoreWebView2Deferral deferral = e.GetDeferral();
        PopupWindow popup = new PopupWindow(this);
        popup.Show(this);

        try
        {
            await popup.View.EnsureCoreWebView2Async(environment);
            Configure(popup.View.CoreWebView2);
            e.NewWindow = popup.View.CoreWebView2;
            e.Handled = true;
        }
        finally
        {
            deferral.Complete();
        }
    }

    private void OnNavigationCompleted(object sender, CoreWebView2NavigationCompletedEventArgs e)
    {
        if (!e.IsSuccess && e.WebErrorStatus != CoreWebView2WebErrorStatus.OperationCanceled && e.WebErrorStatus != CoreWebView2WebErrorStatus.ConnectionAborted)
        {
            view.CoreWebView2.NavigateToString(OfflinePage.Html(server));
            return;
        }

        if (e.IsSuccess && !updateChecked)
        {
            updateChecked = true;
            Updater.CheckInBackground(this, server);
        }
    }

    /// <summary>Buttons on the "cannot reach the server" page.</summary>
    private void OnWebMessage(object sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        string message = e.TryGetWebMessageAsString();

        if (message == "retry")
        {
            view.CoreWebView2.Navigate(server + "/admin");
        }
        else if (message == "change-server")
        {
            string address = ServerDialog.Ask(server);

            if (address != null)
            {
                Settings.Save(address);
                Application.Restart();
            }
        }
    }

    private static void OpenInBrowser(string address)
    {
        try { Process.Start(address); } catch (Exception) { }
    }
}

internal sealed class PopupWindow : Form
{
    public readonly WebView2 View = new WebView2();

    public PopupWindow(Form owner)
    {
        Text = Program.AppTitle;
        Icon = Program.AppIcon();
        StartPosition = FormStartPosition.CenterParent;
        Size = new Size(Math.Min(1100, owner.Width - 80), Math.Min(850, owner.Height - 80));
        View.Dock = DockStyle.Fill;
        Controls.Add(View);

        View.CoreWebView2InitializationCompleted += delegate
        {
            if (View.CoreWebView2 == null)
            {
                return;
            }

            View.CoreWebView2.DocumentTitleChanged += delegate { Text = View.CoreWebView2.DocumentTitle; };
            View.CoreWebView2.WindowCloseRequested += delegate { Close(); };
        };
    }
}

internal static class OfflinePage
{
    internal static string Html(string server)
    {
        return "<!doctype html><html><head><meta charset='utf-8'><title>" + Program.AppTitle + "</title><style>"
            + "body{margin:0;height:100vh;display:grid;place-items:center;background:#f1f3f9;font-family:'Segoe UI',sans-serif;color:#141a3a}"
            + ".card{background:#fff;border-radius:16px;padding:40px 44px;max-width:520px;box-shadow:0 20px 50px -25px rgba(46,58,140,.45);border-top:6px solid #2E3A8C}"
            + "h1{font-size:22px;margin:0 0 10px}p{color:#4a5476;line-height:1.55;margin:0 0 8px}code{background:#f1f3f9;padding:2px 6px;border-radius:6px}"
            + "button{font:inherit;border:0;border-radius:10px;padding:10px 18px;margin:18px 10px 0 0;cursor:pointer}"
            + ".primary{background:#2E3A8C;color:#fff}.secondary{background:#f1f3f9;color:#141a3a;box-shadow:inset 0 0 0 1px #cbd1e2}"
            + "</style></head><body><div class='card'><h1>Cannot reach the COLISAP server</h1>"
            + "<p>The app could not connect to <code>" + WebUtility.HtmlEncode(server) + "</code>.</p>"
            + "<p>Check that this computer is connected to the internet or office network. If it still fails, tell your Administrator.</p>"
            + "<button class='primary' onclick=\"chrome.webview.postMessage('retry')\">Try again</button>"
            + "<button class='secondary' onclick=\"chrome.webview.postMessage('change-server')\">Change server address</button>"
            + "</div></body></html>";
    }
}

/// <summary>Keeps the exe up to date from the server: &lt;server&gt;/downloads/COLISAP.version and COLISAP.exe.</summary>
internal static class Updater
{
    private static string pendingFile;

    private static string ExePath
    {
        get { return Application.ExecutablePath; }
    }

    private static string NewExePath
    {
        get { return ExePath + ".new"; }
    }

    internal static void CheckInBackground(Form window, string server)
    {
        Task.Run(() =>
        {
            try
            {
                using (WebClient client = new WebClient())
                {
                    Version latest;

                    if (!System.Version.TryParse(client.DownloadString(server + "/downloads/COLISAP.version").Trim(), out latest)
                        || latest <= new Version(Program.Version))
                    {
                        return;
                    }

                    client.DownloadFile(server + "/downloads/COLISAP.exe", NewExePath);
                }

                byte[] head = new byte[2];
                using (FileStream stream = File.OpenRead(NewExePath))
                {
                    stream.Read(head, 0, 2);
                }

                if (head[0] != 'M' || head[1] != 'Z' || new FileInfo(NewExePath).Length < 100000)
                {
                    File.Delete(NewExePath);
                    return;
                }

                pendingFile = NewExePath;
                window.BeginInvoke((Action)delegate
                {
                    DialogResult answer = MessageBox.Show(window,
                        "A new version of COLISAP has been downloaded.\n\nRestart now to start using it? (Otherwise it is installed when you close COLISAP.)",
                        Program.AppTitle, MessageBoxButtons.YesNo, MessageBoxIcon.Information);

                    if (answer == DialogResult.Yes)
                    {
                        Swap(true);
                        window.Close();
                    }
                });
            }
            catch (Exception)
            {
                // No update this time (no write access, offline, nothing published): try again next launch.
                try { if (File.Exists(NewExePath)) { File.Delete(NewExePath); } } catch (Exception) { }
            }
        });
    }

    internal static void ApplyOnExit()
    {
        if (pendingFile != null)
        {
            Swap(false);
        }
    }

    /// <summary>The running exe cannot overwrite itself, so a tiny script replaces it once this app has closed.</summary>
    private static void Swap(bool restart)
    {
        pendingFile = null;
        string script = Path.Combine(Path.GetTempPath(), "colisap-update.cmd");
        File.WriteAllText(script,
            "@echo off\r\n"
            + "for /l %%i in (1,1,30) do (\r\n"
            + "  move /y \"" + NewExePath + "\" \"" + ExePath + "\" >nul 2>&1 && goto done\r\n"
            + "  ping -n 2 127.0.0.1 >nul\r\n"
            + ")\r\n"
            + ":done\r\n"
            + (restart ? "start \"\" \"" + ExePath + "\"\r\n" : "")
            + "del \"%~f0\"\r\n");

        ProcessStartInfo info = new ProcessStartInfo("cmd.exe", "/c \"" + script + "\"");
        info.CreateNoWindow = true;
        info.WindowStyle = ProcessWindowStyle.Hidden;
        info.UseShellExecute = false;
        Process.Start(info);
    }

    /// <summary>A downloaded update left over from an interrupted swap is cleaned up on the next start.</summary>
    internal static void FinishPendingUpdate()
    {
        try
        {
            if (File.Exists(NewExePath) && File.GetLastWriteTimeUtc(NewExePath) < DateTime.UtcNow.AddMinutes(-5))
            {
                File.Delete(NewExePath);
            }
        }
        catch (Exception)
        {
        }
    }
}

/// <summary>Remembers the window's size, position and maximized state between sessions.</summary>
internal static class WindowPlacement
{
    private static string FilePath
    {
        get { return Path.Combine(Settings.Folder, "window.txt"); }
    }

    internal static void Restore(Form form)
    {
        form.StartPosition = FormStartPosition.Manual;
        form.Bounds = Screen.PrimaryScreen.WorkingArea;
        form.WindowState = FormWindowState.Maximized;

        try
        {
            string[] parts = File.ReadAllText(FilePath).Split(',');
            Rectangle bounds = new Rectangle(int.Parse(parts[0]), int.Parse(parts[1]), int.Parse(parts[2]), int.Parse(parts[3]));

            if (Screen.FromRectangle(bounds).WorkingArea.IntersectsWith(bounds))
            {
                form.Bounds = bounds;
                form.WindowState = parts[4] == "1" ? FormWindowState.Maximized : FormWindowState.Normal;
            }
        }
        catch (Exception)
        {
        }
    }

    internal static void Save(Form form)
    {
        try
        {
            Rectangle bounds = form.WindowState == FormWindowState.Normal ? form.Bounds : form.RestoreBounds;
            Directory.CreateDirectory(Settings.Folder);
            File.WriteAllText(FilePath, bounds.X + "," + bounds.Y + "," + bounds.Width + "," + bounds.Height + "," + (form.WindowState == FormWindowState.Maximized ? "1" : "0"));
        }
        catch (Exception)
        {
        }
    }
}

/// <summary>Remembers the server address per Windows user; a COLISAP.ini beside the exe can suggest one.</summary>
internal static class Settings
{
    internal static string Folder
    {
        get { return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "COLISAP"); }
    }

    private static string FilePath
    {
        get { return Path.Combine(Folder, "server.txt"); }
    }

    internal static string SavedServer()
    {
        try
        {
            string value = File.Exists(FilePath) ? File.ReadAllText(FilePath).Trim() : "";
            return value.Length > 0 ? value : null;
        }
        catch (Exception)
        {
            return null;
        }
    }

    /// <summary>Reads "ServerUrl=https://..." from COLISAP.ini next to the exe, so an Administrator can hand out the address with it.</summary>
    internal static string ServerFromIni()
    {
        try
        {
            string ini = Path.Combine(Path.GetDirectoryName(Application.ExecutablePath), "COLISAP.ini");

            if (!File.Exists(ini))
            {
                return null;
            }

            foreach (string line in File.ReadAllLines(ini))
            {
                string trimmed = line.Trim();

                if (trimmed.StartsWith("ServerUrl=", StringComparison.OrdinalIgnoreCase))
                {
                    string value = trimmed.Substring("ServerUrl=".Length).Trim();
                    return value.Length > 0 ? value : null;
                }
            }
        }
        catch (Exception)
        {
        }

        return null;
    }

    internal static void Save(string server)
    {
        try
        {
            Directory.CreateDirectory(Folder);
            File.WriteAllText(FilePath, server);
        }
        catch (Exception)
        {
        }
    }

    /// <summary>"192.168.1.5:8001/admin/" becomes "http://192.168.1.5:8001".</summary>
    internal static string Normalize(string input)
    {
        string value = (input ?? "").Trim();

        if (value.Length == 0)
        {
            return null;
        }

        if (!value.StartsWith("http://", StringComparison.OrdinalIgnoreCase) && !value.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
        {
            value = "http://" + value;
        }

        value = value.TrimEnd('/');

        if (value.EndsWith("/admin", StringComparison.OrdinalIgnoreCase))
        {
            value = value.Substring(0, value.Length - "/admin".Length);
        }

        Uri uri;
        return Uri.TryCreate(value, UriKind.Absolute, out uri) ? value : null;
    }
}

internal static class Connection
{
    /// <summary>Null when the COLISAP sign-in page answers, otherwise a plain explanation of what went wrong.</summary>
    internal static string Check(string server)
    {
        try
        {
            HttpWebRequest request = (HttpWebRequest)WebRequest.Create(server.TrimEnd('/') + "/admin/login");
            request.Timeout = 12000;
            request.UserAgent = "COLISAP-Desktop/" + Program.Version;

            using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
            using (StreamReader reader = new StreamReader(response.GetResponseStream()))
            {
                return reader.ReadToEnd().IndexOf("COLISAP", StringComparison.OrdinalIgnoreCase) >= 0
                    ? null
                    : "Something answered at this address, but it is not the COLISAP system.";
            }
        }
        catch (WebException exception)
        {
            HttpWebResponse response = exception.Response as HttpWebResponse;

            if (response != null)
            {
                return "The server answered with an error (HTTP " + (int)response.StatusCode + ").";
            }

            return exception.Status == WebExceptionStatus.Timeout ? "The server did not answer in time." : "No connection (" + exception.Message + ")";
        }
        catch (Exception exception)
        {
            return exception.Message;
        }
    }
}

/// <summary>The WebView2 libraries are stored inside the exe, so COLISAP stays a single file.</summary>
internal static class EmbeddedLibraries
{
    internal static Assembly Resolve(object sender, ResolveEventArgs args)
    {
        string name = new AssemblyName(args.Name).Name;

        if (!name.StartsWith("Microsoft.Web.WebView2", StringComparison.Ordinal))
        {
            return null;
        }

        using (Stream stream = Assembly.GetExecutingAssembly().GetManifestResourceStream(name + ".dll"))
        {
            if (stream == null)
            {
                return null;
            }

            byte[] bytes = new byte[stream.Length];
            stream.Read(bytes, 0, bytes.Length);
            return Assembly.Load(bytes);
        }
    }

    /// <summary>Writes the small native WebView2 loader next to the app's data and returns its folder.</summary>
    internal static string ExtractLoader()
    {
        string platform = Environment.Is64BitProcess ? "x64" : "x86";
        string folder = Program.LocalFolder("bin\\" + platform);
        string target = Path.Combine(folder, "WebView2Loader.dll");

        using (Stream stream = Assembly.GetExecutingAssembly().GetManifestResourceStream("WebView2Loader." + platform + ".dll"))
        {
            if (!File.Exists(target) || new FileInfo(target).Length != stream.Length)
            {
                using (FileStream file = File.Create(target))
                {
                    stream.CopyTo(file);
                }
            }
        }

        return folder;
    }
}

internal static class ServerDialog
{
    /// <summary>Asks for the server address and checks that COLISAP answers there; null when the person cancels.</summary>
    internal static string Ask(string current)
    {
        Form form = Program.NewDialog(Program.AppTitle + " — Server address", 540, 260);

        Label title = new Label();
        title.Text = "Connect to the COLISAP server";
        title.Font = new Font("Segoe UI Semibold", 13f);
        title.SetBounds(24, 24, 490, 30);
        form.Controls.Add(title);

        Label hint = new Label();
        hint.Text = "Normally this is " + Program.DefaultServer + "\nUse another address only if your Administrator tells you to.";
        hint.ForeColor = Color.FromArgb(74, 84, 118);
        hint.SetBounds(24, 60, 490, 44);
        form.Controls.Add(hint);

        TextBox address = new TextBox();
        address.Text = current ?? "";
        address.SetBounds(24, 112, 490, 30);
        form.Controls.Add(address);

        Label error = new Label();
        error.ForeColor = Color.FromArgb(190, 18, 60);
        error.SetBounds(24, 146, 490, 44);
        form.Controls.Add(error);

        Button connect = Program.NewButton("Connect", 374, 204, true);
        Button cancel = Program.NewButton("Cancel", 224, 204, false);
        cancel.DialogResult = DialogResult.Cancel;
        form.Controls.Add(connect);
        form.Controls.Add(cancel);
        form.AcceptButton = connect;
        form.CancelButton = cancel;

        string result = null;
        connect.Click += delegate
        {
            string normalized = Settings.Normalize(address.Text);

            if (normalized == null)
            {
                error.Text = "That is not a valid address.";
                return;
            }

            error.ForeColor = Color.FromArgb(74, 84, 118);
            error.Text = "Connecting…";
            form.UseWaitCursor = true;
            Application.DoEvents();
            string problem = Connection.Check(normalized);
            form.UseWaitCursor = false;

            if (problem != null)
            {
                error.ForeColor = Color.FromArgb(190, 18, 60);
                error.Text = problem;
                return;
            }

            result = normalized;
            form.DialogResult = DialogResult.OK;
        };

        return form.ShowDialog() == DialogResult.OK ? result : null;
    }
}

internal static class ChoiceDialog
{
    /// <summary>Three-button message: Retry = first, Ignore = second, Cancel = third.</summary>
    internal static DialogResult Show(string heading, string message, string first, string second, string third)
    {
        Form form = Program.NewDialog(Program.AppTitle, 580, 300);

        Label title = new Label();
        title.Text = heading;
        title.Font = new Font("Segoe UI Semibold", 13f);
        title.SetBounds(24, 24, 530, 30);
        form.Controls.Add(title);

        Label body = new Label();
        body.Text = message;
        body.ForeColor = Color.FromArgb(52, 61, 96);
        body.SetBounds(24, 60, 530, 170);
        form.Controls.Add(body);

        Button retry = Program.NewButton(first, 414, 244, true);
        retry.DialogResult = DialogResult.Retry;
        Button change = Program.NewButton(second, 264, 244, false);
        change.DialogResult = DialogResult.Ignore;
        Button close = Program.NewButton(third, 114, 244, false);
        close.DialogResult = DialogResult.Cancel;
        form.Controls.Add(retry);
        form.Controls.Add(change);
        form.Controls.Add(close);
        form.AcceptButton = retry;
        form.CancelButton = close;

        return form.ShowDialog();
    }
}
