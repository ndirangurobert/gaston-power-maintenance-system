const SESSION_SECONDS = 60 * 60 * 8;
const encoder = new TextEncoder();
const roles = { admin: 3, technician: 2, viewer: 1 };

function json(data, status = 200, headers = {}) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", ...headers }
  });
}
function html(content, status = 200) {
  return new Response(content, { status, headers: { "content-type": "text/html; charset=utf-8", "cache-control": "no-store" } });
}
function cookieValue(request, name) {
  const raw = request.headers.get("Cookie") || "";
  const part = raw.split(";").map(x => x.trim()).find(x => x.startsWith(name + "="));
  return part ? part.slice(name.length + 1) : "";
}
function randomHex(bytes = 32) {
  const data = crypto.getRandomValues(new Uint8Array(bytes));
  return [...data].map(x => x.toString(16).padStart(2, "0")).join("");
}
function base64(bytes) {
  let s = "";
  for (const b of new Uint8Array(bytes)) s += String.fromCharCode(b);
  return btoa(s);
}
function fromBase64(s) {
  return Uint8Array.from(atob(s), c => c.charCodeAt(0));
}
async function passwordHash(password, saltText) {
  const salt = saltText ? fromBase64(saltText) : crypto.getRandomValues(new Uint8Array(16));
  const key = await crypto.subtle.importKey("raw", encoder.encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", hash: "SHA-256", salt, iterations: 100000 }, key, 256);
  return { salt: base64(salt), hash: base64(bits) };
}
async function passwordMatches(password, salt, expected) {
  const result = await passwordHash(password, salt);
  const a = encoder.encode(result.hash), b = encoder.encode(expected);
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
  return diff === 0;
}
function validEmail(value) {
  return typeof value === "string" && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) && value.length <= 190;
}
async function readBody(request) {
  try { return await request.json(); } catch { return {}; }
}
async function currentUser(request, env) {
  const sid = cookieValue(request, "gp_session");
  if (!sid) return null;
  const row = await env.DB.prepare(
    "SELECT s.id AS session_id,s.csrf_token,s.expires_at,u.id,u.name,u.email,u.role,u.active FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=?"
  ).bind(sid).first();
  if (!row || !row.active || row.expires_at < Math.floor(Date.now() / 1000)) {
    if (row) await env.DB.prepare("DELETE FROM sessions WHERE id=?").bind(sid).run();
    return null;
  }
  return row;
}
function requireRole(user, minimum) {
  return !!user && (roles[user.role] || 0) >= (roles[minimum] || 99);
}
async function audit(env, userId, action, details = "") {
  await env.DB.prepare("INSERT INTO audit_logs(user_id,action,details) VALUES(?,?,?)")
    .bind(userId || null, action, String(details).slice(0, 500)).run();
}
function safeText(v, max = 500) { return String(v ?? "").trim().slice(0, max); }
function error(message, status = 400) { return json({ error: message }, status); }

const collections = {
  customers: {
    table: "customers", minRole: "technician",
    fields: ["name","phone","email","address","notes"],
    required: ["name"], search: "name"
  },
  generators: {
    table: "generators", minRole: "technician",
    fields: ["customer_id","name","model","serial_number","capacity_kva","location","status","notes"],
    required: ["name"], search: "name"
  },
  jobs: {
    table: "maintenance_jobs", minRole: "technician",
    fields: ["generator_id","technician_id","job_type","scheduled_date","completed_date","findings","work_done","status","cost"],
    required: ["generator_id","job_type"], search: "job_type"
  },
  parts: {
    table: "spare_parts", minRole: "technician",
    fields: ["name","part_number","quantity","reorder_level","unit_cost","supplier"],
    required: ["name"], search: "name"
  }
};
function cleanRecord(config, input) {
  const out = {};
  for (const field of config.fields) {
    if (!(field in input)) continue;
    const value = input[field];
    if (["customer_id","technician_id","generator_id","capacity_kva","quantity","reorder_level","unit_cost","cost"].includes(field)) {
      out[field] = value === "" || value === null ? null : Number(value);
      if (out[field] !== null && !Number.isFinite(out[field])) throw new Error("Invalid number in " + field);
    } else {
      out[field] = safeText(value, field === "notes" || field === "findings" || field === "work_done" ? 3000 : 500);
    }
  }
  for (const field of config.required) if (!out[field]) throw new Error(field + " is required");
  if (out.email && !validEmail(out.email)) throw new Error("Enter a valid email address");
  return out;
}
async function api(request, env) {
  const url = new URL(request.url);
  const path = url.pathname.replace(/^\/api\/?/, "");
  const method = request.method;
  const user = await currentUser(request, env);

  if (path === "setup" && method === "GET") {
    const row = await env.DB.prepare("SELECT COUNT(*) AS total FROM users").first();
    return json({ setupRequired: row.total === 0 });
  }
  if (path === "setup" && method === "POST") {
    const body = await readBody(request);
    const name = safeText(body.name, 100), email = safeText(body.email, 190).toLowerCase();
    if (!name || !validEmail(email) || typeof body.password !== "string" || body.password.length < 12) {
      return error("Enter a name, valid email, and a password of at least 12 characters.");
    }
    const p = await passwordHash(body.password);
    // A single conditional INSERT makes the first-admin check and creation one
    // database operation, preventing concurrent setup requests from creating
    // multiple initial administrators.
    const result = await env.DB.prepare(
      "INSERT INTO users(name,email,password_hash,role) SELECT ?,?,?,'admin' WHERE NOT EXISTS (SELECT 1 FROM users)"
    ).bind(name, email, p.salt + ":" + p.hash).run();
    if (!result.meta || result.meta.changes !== 1) {
      return error("Initial setup has already been completed.", 409);
    }
    return json({ message: "Administrator created. You can now sign in." }, 201);
  }
  if (path === "login" && method === "POST") {
    const body = await readBody(request);
    const email = safeText(body.email, 190).toLowerCase();
    const identity = (request.headers.get("CF-Connecting-IP") || "unknown") + ":" + email;
    const since = Math.floor(Date.now() / 1000) - 900;
    await env.DB.prepare("DELETE FROM login_attempts WHERE attempted_at < ?").bind(since).run();
    const attempts = await env.DB.prepare("SELECT COUNT(*) AS n FROM login_attempts WHERE identity=? AND attempted_at>=?")
      .bind(identity, since).first();
    if (attempts.n >= 8) return error("Too many sign-in attempts. Wait 15 minutes and try again.", 429);
    const row = await env.DB.prepare("SELECT * FROM users WHERE email=? AND active=1").bind(email).first();
    let ok = false;
    if (row && typeof body.password === "string") {
      const [salt, hash] = row.password_hash.split(":");
      ok = await passwordMatches(body.password, salt, hash);
    }
    if (!ok) {
      await env.DB.prepare("INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)").bind(identity, Math.floor(Date.now()/1000)).run();
      return error("Email or password is incorrect.", 401);
    }
    await env.DB.prepare("DELETE FROM login_attempts WHERE identity=?").bind(identity).run();
    const sid = randomHex(), csrf = randomHex();
    await env.DB.prepare("INSERT INTO sessions(id,user_id,csrf_token,expires_at) VALUES(?,?,?,?)")
      .bind(sid, row.id, csrf, Math.floor(Date.now()/1000) + SESSION_SECONDS).run();
    await audit(env, row.id, "login", "Successful sign in");
    return json({ user: { id: row.id, name: row.name, email: row.email, role: row.role }, csrf }, 200, {
      "set-cookie": "gp_session=" + sid + "; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=" + SESSION_SECONDS
    });
  }
  if (path === "logout" && method === "POST") {
    if (user) await env.DB.prepare("DELETE FROM sessions WHERE id=?").bind(user.session_id).run();
    return json({ message: "Signed out." }, 200, { "set-cookie": "gp_session=; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=0" });
  }
  if (!user) return error("Please sign in.", 401);
  if (method !== "GET" && request.headers.get("x-csrf-token") !== user.csrf_token) return error("Security token expired. Refresh the page.", 403);

  if (path === "me" && method === "GET") return json({ user: { id:user.id, name:user.name, email:user.email, role:user.role }, csrf:user.csrf_token });
  if (path === "dashboard" && method === "GET") {
    const [customers,generators,jobs,parts,low,upcoming] = await Promise.all([
      env.DB.prepare("SELECT COUNT(*) n FROM customers").first(),
      env.DB.prepare("SELECT COUNT(*) n FROM generators").first(),
      env.DB.prepare("SELECT COUNT(*) n FROM maintenance_jobs WHERE status IN ('scheduled','in_progress')").first(),
      env.DB.prepare("SELECT COUNT(*) n FROM spare_parts").first(),
      env.DB.prepare("SELECT COUNT(*) n FROM spare_parts WHERE quantity<=reorder_level").first(),
      env.DB.prepare("SELECT j.id,j.job_type,j.scheduled_date,j.status,g.name AS generator_name,c.name AS customer_name FROM maintenance_jobs j JOIN generators g ON g.id=j.generator_id LEFT JOIN customers c ON c.id=g.customer_id WHERE j.status IN ('scheduled','in_progress') ORDER BY j.scheduled_date LIMIT 8").all()
    ]);
    return json({ stats:{customers:customers.n,generators:generators.n,openJobs:jobs.n,parts:parts.n,lowStock:low.n}, upcoming:upcoming.results });
  }
  if (path === "staff" && method === "GET") {
    if (!requireRole(user,"admin")) return error("Administrator access required.",403);
    const result = await env.DB.prepare("SELECT id,name,email,role,active,created_at FROM users ORDER BY name").all();
    return json({ items: result.results });
  }
  if (path === "staff" && method === "POST") {
    if (!requireRole(user,"admin")) return error("Administrator access required.",403);
    const b = await readBody(request), name=safeText(b.name,100), email=safeText(b.email,190).toLowerCase(), role=safeText(b.role,20);
    if (!name || !validEmail(email) || !["admin","technician","viewer"].includes(role) || typeof b.password!=="string" || b.password.length<12) return error("Provide a name, valid email, role and password of at least 12 characters.");
    const p=await passwordHash(b.password);
    try {
      await env.DB.prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)").bind(name,email,p.salt+":"+p.hash,role).run();
    } catch { return error("That email may already be registered.",409); }
    await audit(env,user.id,"staff_created",email);
    return json({message:"Staff account created."},201);
  }
  if (path === "password" && method === "POST") {
    const b=await readBody(request);
    if (typeof b.currentPassword!=="string" || typeof b.newPassword!=="string" || b.newPassword.length<12) return error("New password must contain at least 12 characters.");
    const row=await env.DB.prepare("SELECT password_hash FROM users WHERE id=?").bind(user.id).first();
    const [salt,hash]=row.password_hash.split(":");
    if (!await passwordMatches(b.currentPassword,salt,hash)) return error("Current password is incorrect.",403);
    const p=await passwordHash(b.newPassword);
    await env.DB.prepare("UPDATE users SET password_hash=? WHERE id=?").bind(p.salt+":"+p.hash,user.id).run();
    await env.DB.prepare("DELETE FROM sessions WHERE user_id=?").bind(user.id).run();
    await audit(env,user.id,"password_changed","Password updated; sessions revoked");
    return json({message:"Password changed. Please sign in again."},200,{"set-cookie":"gp_session=; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=0"});
  }


  if (path === "help" && method === "GET") {
    if (!requireRole(user,"technician")) return error("Technician access required.",403);
    const result = user.role === "admin"
      ? await env.DB.prepare("SELECT h.*,c.name AS customer_name,u.name AS technician_name,creator.name AS creator_name FROM customer_help_requests h LEFT JOIN customers c ON c.id=h.customer_id LEFT JOIN users u ON u.id=h.assigned_to LEFT JOIN users creator ON creator.id=h.created_by ORDER BY h.id DESC LIMIT 300").all()
      : await env.DB.prepare("SELECT h.*,c.name AS customer_name,u.name AS technician_name,creator.name AS creator_name FROM customer_help_requests h LEFT JOIN customers c ON c.id=h.customer_id LEFT JOIN users u ON u.id=h.assigned_to LEFT JOIN users creator ON creator.id=h.created_by WHERE h.assigned_to=? ORDER BY h.id DESC LIMIT 300").bind(user.id).all();
    return json({items:result.results});
  }
  if (path === "help" && method === "POST") {
    if (!requireRole(user,"admin")) return error("Administrator access required.",403);
    const b=await readBody(request), customerId=Number(b.customer_id), assignedTo=b.assigned_to===""||b.assigned_to==null?null:Number(b.assigned_to);
    const subject=safeText(b.subject,160), message=safeText(b.message,3000);
    if (!Number.isInteger(customerId) || customerId<1 || !subject || !message) return error("Choose a customer and enter a subject and message.");
    if (assignedTo!==null && (!Number.isInteger(assignedTo) || !(await env.DB.prepare("SELECT id FROM users WHERE id=? AND role='technician' AND active=1").bind(assignedTo).first()))) return error("Choose an active technician.");
    try {
      const result=await env.DB.prepare("INSERT INTO customer_help_requests(customer_id,subject,message,assigned_to,created_by) VALUES(?,?,?,?,?)").bind(customerId,subject,message,assignedTo,user.id).run();
      await audit(env,user.id,"customer_help_created","Request "+result.meta.last_row_id);
      return json({message:"Customer help request created.",id:result.meta.last_row_id},201);
    } catch { return error("Could not create the request. Check that the selected customer exists."); }
  }
  const helpMatch=path.match(/^help\/(\d+)$/);
  if (helpMatch && method === "PUT") {
    if (!requireRole(user,"technician")) return error("Technician access required.",403);
    const id=Number(helpMatch[1]), b=await readBody(request);
    const existing=await env.DB.prepare("SELECT * FROM customer_help_requests WHERE id=?").bind(id).first();
    if (!existing) return error("Help request not found.",404);
    if (user.role!=="admin" && existing.assigned_to!==user.id) return error("You can only update requests assigned to you.",403);
    const status=safeText(b.status,20), response=safeText(b.staff_response,3000);
    if (!["pending","in_progress","resolved"].includes(status)) return error("Choose a valid request status.");
    if (user.role==="admin") {
      const assignedTo=b.assigned_to===""||b.assigned_to==null?null:Number(b.assigned_to);
      if (assignedTo!==null && (!Number.isInteger(assignedTo) || !(await env.DB.prepare("SELECT id FROM users WHERE id=? AND role='technician' AND active=1").bind(assignedTo).first()))) return error("Choose an active technician.");
      await env.DB.prepare("UPDATE customer_help_requests SET status=?,staff_response=?,assigned_to=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(status,response,assignedTo,id).run();
    } else {
      await env.DB.prepare("UPDATE customer_help_requests SET status=?,staff_response=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND assigned_to=?").bind(status,response,id,user.id).run();
    }
    await audit(env,user.id,"customer_help_updated","Request "+id+" status "+status);
    return json({message:"Customer help request updated."});
  }

  const match = path.match(/^(customers|generators|jobs|parts)(?:\/(\d+))?$/);
  if (match) {
    const key=match[1], id=match[2], cfg=collections[key];
    if (method==="GET") {
      const result=await env.DB.prepare(key==="jobs"
        ? "SELECT j.*,g.name AS generator_name,u.name AS technician_name FROM maintenance_jobs j JOIN generators g ON g.id=j.generator_id LEFT JOIN users u ON u.id=j.technician_id ORDER BY j.id DESC LIMIT 300"
        : key==="generators"
          ? "SELECT g.*,c.name AS customer_name FROM generators g LEFT JOIN customers c ON c.id=g.customer_id ORDER BY g.id DESC LIMIT 300"
          : "SELECT * FROM "+cfg.table+" ORDER BY id DESC LIMIT 300").all();
      return json({items:result.results});
    }
    if (!requireRole(user,cfg.minRole)) return error("You do not have permission to make changes.",403);
    if (method==="POST" && !id) {
      const b=await readBody(request);
      let data; try { data=cleanRecord(cfg,b); } catch(e) { return error(e.message); }
      const fields=Object.keys(data), sql="INSERT INTO "+cfg.table+" ("+fields.join(",")+") VALUES ("+fields.map(()=>"?").join(",")+")";
      try {
        const result=await env.DB.prepare(sql).bind(...fields.map(f=>data[f])).run();
        await audit(env,user.id,key+"_created","Record "+result.meta.last_row_id);
        return json({message:"Record created.",id:result.meta.last_row_id},201);
      } catch { return error("Could not save the record. Check linked records and unique values."); }
    }
    if (method==="PUT" && id) {
      const b=await readBody(request);
      let data; try { data=cleanRecord(cfg,b); } catch(e) { return error(e.message); }
      const fields=Object.keys(data);
      if (!fields.length) return error("No changes supplied.");
      try {
        await env.DB.prepare("UPDATE "+cfg.table+" SET "+fields.map(f=>f+"=?").join(",")+" WHERE id=?").bind(...fields.map(f=>data[f]),Number(id)).run();
        await audit(env,user.id,key+"_updated","Record "+id);
        return json({message:"Record updated."});
      } catch { return error("Could not update the record."); }
    }
    if (method==="DELETE" && id) {
      if (!requireRole(user,"admin")) return error("Only an administrator can delete records.",403);
      await env.DB.prepare("DELETE FROM "+cfg.table+" WHERE id=?").bind(Number(id)).run();
      await audit(env,user.id,key+"_deleted","Record "+id);
      return json({message:"Record deleted."});
    }
  }
  return error("Not found.",404);
}

function page() {
return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gaston Power | Maintenance Management</title><style>
:root{font-family:Inter,ui-sans-serif,system-ui,Arial;color:#172033;background:#f3f5f9}*{box-sizing:border-box}body{margin:0}.top{background:#101b31;color:white;padding:18px 5%;display:flex;justify-content:space-between;align-items:center}.brand{font-size:20px;font-weight:800}.brand span{color:#f3a712}.wrap{max-width:1180px;margin:28px auto;padding:0 18px}.panel{background:white;border:1px solid #e5e9f0;border-radius:14px;padding:22px;box-shadow:0 8px 24px #14213a0a;margin-bottom:18px}.hidden{display:none!important}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px}.stat{background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:18px}.stat b{font-size:28px;display:block;margin-top:8px;color:#10203a}.muted{color:#68758a;font-size:13px}h1{font-size:25px;margin:0 0 8px}h2{font-size:18px;margin:0 0 16px}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.tabs button,.btn{background:#14233d;color:white;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}.tabs button.active,.btn.primary{background:#e99b09;color:#111827}.btn.danger{background:#9b2634;color:white}input,select,textarea{width:100%;padding:11px;border:1px solid #d7deea;border-radius:8px;margin:6px 0 12px;font:inherit}label{font-size:13px;font-weight:650}form{max-width:600px}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:11px;border-bottom:1px solid #edf0f5}th{color:#68758a;font-size:12px;text-transform:uppercase}.tablewrap{overflow:auto}.notice{padding:12px;border-radius:8px;background:#fff4d9;margin:12px 0}.success{background:#e5f7ed}.error{background:#ffebed;color:#84202b}.login{max-width:440px;margin:8vh auto}.right{display:flex;gap:10px;align-items:center}.badge{display:inline-block;padding:4px 8px;border-radius:20px;background:#eef2f7;font-size:12px}.row{display:flex;gap:12px;flex-wrap:wrap}.row>*{flex:1}@media(max-width:600px){.top{padding:15px}.wrap{margin:16px auto}.panel{padding:16px}table{min-width:650px}}
</style></head><body><header class="top"><div class="brand">GASTON <span>POWER</span> <small style="font-size:11px;font-weight:500;color:#c8d1df">KENYA LTD</small></div><div id="who" class="right"></div></header><main class="wrap">
<section id="auth" class="panel login"><h1>Maintenance Portal</h1><p class="muted">Sign in to manage customers, generators and service operations.</p><div id="setupBox" class="hidden"><h2>Initial administrator setup</h2><form id="setupForm"><label>Administrator name</label><input name="name" required><label>Email</label><input name="email" type="email" required><label>Password (minimum 12 characters)</label><input name="password" type="password" minlength="12" required><button class="btn primary">Create administrator</button></form></div><div id="loginBox"><form id="loginForm"><label>Email address</label><input name="email" type="email" required><label>Password</label><input name="password" type="password" required><button class="btn primary">Sign in</button></form></div><div id="authMsg" class="muted"></div></section>
<section id="app" class="hidden"><div class="row" style="align-items:center"><div><h1 id="heading">Overview</h1><p class="muted">Generator servicing, customer records and inventory.</p></div><div style="text-align:right"><button class="btn" id="logout">Sign out</button></div></div><nav class="tabs" id="tabs"><button data-tab="dashboard" class="active">Dashboard</button><button data-tab="customers">Customers</button><button data-tab="generators">Generators</button><button data-tab="jobs">Maintenance jobs</button><button data-tab="parts">Spare parts</button><button data-tab="help" id="helpTab" class="hidden">Customer Help</button><button data-tab="password">Change password</button><button data-tab="staff" id="staffTab" class="hidden">Staff</button></nav><div id="content"></div></section>
</main><script>
const $=s=>document.querySelector(s);let me=null,csrf="";
async function api(path,method="GET",body){const r=await fetch("/api/"+path,{method,headers:{"content-type":"application/json",...(method!=="GET"?{"x-csrf-token":csrf}:{})},body:body?JSON.stringify(body):undefined});const d=await r.json().catch(()=>({error:"Unexpected server response"}));if(!r.ok)throw Error(d.error||"Request failed");return d}
function esc(v){return String(v??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]))}
function msg(el,text,bad=false){el.textContent=text;el.className=bad?"notice error":"notice success"}
async function init(){try{const s=await fetch("/api/setup").then(r=>r.json());if(s.setupRequired){$("#setupBox").classList.remove("hidden");$("#loginBox").classList.add("hidden");}else{const r=await fetch("/api/me");if(r.ok){const d=await r.json();me=d.user;csrf=d.csrf;showApp()} }}catch(e){$("#authMsg").textContent=e.message}}
$("#setupForm").onsubmit=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));try{const d=await api("setup","POST",b);$("#authMsg").textContent=d.message;$("#setupBox").classList.add("hidden");$("#loginBox").classList.remove("hidden")}catch(x){$("#authMsg").textContent=x.message}};
$("#loginForm").onsubmit=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));try{const d=await api("login","POST",b);me=d.user;csrf=d.csrf;showApp()}catch(x){$("#authMsg").textContent=x.message}};
$("#logout").onclick=async()=>{try{await api("logout","POST",{})}finally{location.reload()}};
function showApp(){$("#auth").classList.add("hidden");$("#app").classList.remove("hidden");$("#who").textContent=me.name+" · "+me.role;$("#staffTab").classList.toggle("hidden",me.role!=="admin");$("#helpTab").classList.toggle("hidden",!["admin","technician"].includes(me.role));load("dashboard")}
$("#tabs").onclick=e=>{const b=e.target.closest("button[data-tab]");if(!b)return;document.querySelectorAll(".tabs button").forEach(x=>x.classList.toggle("active",x===b));load(b.dataset.tab)};
const defs={
customers:{title:"Customers",fields:[["name","Customer / company name"],["phone","Phone"],["email","Email"],["address","Address"],["notes","Notes","textarea"]],cols:["name","phone","email","address"]},
generators:{title:"Generators",fields:[["customer_id","Customer ID (optional)","number"],["name","Generator name"],["model","Model"],["serial_number","Serial number"],["capacity_kva","Capacity (kVA)","number"],["location","Location"],["status","Status","select",["active","maintenance","inactive"]],["notes","Notes","textarea"]],cols:["name","model","serial_number","capacity_kva","location","status"]},
jobs:{title:"Maintenance jobs",fields:[["generator_id","Generator ID","number"],["technician_id","Technician user ID (optional)","number"],["job_type","Job type"],["scheduled_date","Scheduled date","date"],["completed_date","Completed date","date"],["status","Status","select",["scheduled","in_progress","completed","cancelled"]],["cost","Cost (KES)","number"],["findings","Findings","textarea"],["work_done","Work performed","textarea"]],cols:["id","generator_name","job_type","scheduled_date","status","technician_name"]},
parts:{title:"Spare parts",fields:[["name","Part name"],["part_number","Part number"],["quantity","Quantity","number"],["reorder_level","Reorder level","number"],["unit_cost","Unit cost (KES)","number"],["supplier","Supplier"]],cols:["name","part_number","quantity","reorder_level","unit_cost","supplier"]}
};
function formHtml(key){const d=defs[key];return '<div class="panel"><h2>Add '+d.title.slice(0,-1)+'</h2><form id="recordForm" class="grid">'+d.fields.map(([n,l,t,opts])=>'<div><label>'+l+'</label>'+(t==="textarea"?'<textarea name="'+n+'"></textarea>':t==="select"?'<select name="'+n+'">'+opts.map(o=>'<option>'+o+'</option>').join("")+'</select>':'<input name="'+n+'" '+(t==="number"?'type="number" step="any"':'type="'+(t||"text")+'"')+'>')+'</div>').join("")+'<div style="align-self:end"><button class="btn primary">Save record</button></div></form><div id="formMsg"></div></div>'}
async function load(tab){$("#heading").textContent=tab==="dashboard"?"Dashboard":tab==="password"?"Change password":tab==="staff"?"Staff accounts":tab==="help"?"Customer Help":defs[tab]?.title||"Dashboard";const c=$("#content");c.innerHTML='<div class="panel muted">Loading…</div>';try{
if(tab==="dashboard"){const d=await api("dashboard");c.innerHTML='<div class="grid">'+Object.entries({Customers:d.stats.customers,Generators:d.stats.generators,"Open jobs":d.stats.openJobs,"Spare part types":d.stats.parts,"Low-stock items":d.stats.lowStock}).map(([k,v])=>'<div class="stat"><span class="muted">'+k+'</span><b>'+v+'</b></div>').join("")+'</div><div class="panel" style="margin-top:18px"><h2>Upcoming maintenance</h2>'+table(d.upcoming,["generator_name","customer_name","job_type","scheduled_date","status"])+'</div>';return}
if(tab==="password"){c.innerHTML='<div class="panel"><h2>Update your password</h2><form id="pw"><label>Current password</label><input name="currentPassword" type="password" required><label>New password (minimum 12 characters)</label><input name="newPassword" type="password" minlength="12" required><button class="btn primary">Change password</button></form><div id="pwMsg"></div></div>';$("#pw").onsubmit=async e=>{e.preventDefault();try{const d=await api("password","POST",Object.fromEntries(new FormData(e.target)));msg($("#pwMsg"),d.message);setTimeout(()=>location.reload(),1200)}catch(x){msg($("#pwMsg"),x.message,true)}};return}
if(tab==="help"){
const d=await api("help");
let form="",techs=[];
if(me.role==="admin"){
const [customers,staff]=await Promise.all([api("customers"),api("staff")]);
techs=staff.items.filter(x=>x.role==="technician"&&x.active);
form='<div class="panel"><h2>Create customer help request</h2><form id="helpForm"><label>Customer</label><select name="customer_id" required><option value="">Select customer</option>'+customers.items.map(x=>'<option value="'+x.id+'">'+esc(x.name)+'</option>').join("")+'</select><label>Subject</label><input name="subject" required maxlength="160"><label>Request details</label><textarea name="message" required rows="4"></textarea><label>Assign technician</label><select name="assigned_to"><option value="">Unassigned</option>'+techs.map(x=>'<option value="'+x.id+'">'+esc(x.name)+'</option>').join("")+'</select><button class="btn primary">Create request</button></form><div id="helpFormMsg"></div></div>';
}
c.innerHTML=form+'<div class="panel"><h2>'+(me.role==="admin"?"All customer help requests":"Requests assigned to me")+'</h2>'+table(d.items,["id","customer_name","subject","message","status","technician_name","staff_response","created_at"])+'</div>'+d.items.map(x=>'<div class="panel"><h2>Request #'+x.id+' — '+esc(x.subject)+'</h2><p class="muted">'+esc(x.customer_name||"Customer")+' · '+esc(x.status)+'</p><p>'+esc(x.message)+'</p><form class="helpUpdate" data-id="'+x.id+'"><label>Status</label><select name="status">'+["pending","in_progress","resolved"].map(v=>'<option '+(x.status===v?'selected':'')+'>'+v+'</option>').join("")+'</select><label>Staff response</label><textarea name="staff_response" rows="3">'+esc(x.staff_response||"")+'</textarea>'+(me.role==="admin"?'<label>Assigned technician</label><select name="assigned_to"><option value="">Unassigned</option>'+techs.map(t=>'<option value="'+t.id+'" '+(x.assigned_to===t.id?'selected':'')+'>'+esc(t.name)+'</option>').join("")+'</select>':'')+'<button class="btn primary">Save update</button></form><div class="helpMsg" id="helpMsg'+x.id+'"></div></div>').join("");
if($("#helpForm"))$("#helpForm").onsubmit=async e=>{e.preventDefault();try{const b=Object.fromEntries(new FormData(e.target));const out=await api("help","POST",b);msg($("#helpFormMsg"),out.message);load("help")}catch(x){msg($("#helpFormMsg"),x.message,true)}};
document.querySelectorAll(".helpUpdate").forEach(form=>form.onsubmit=async e=>{e.preventDefault();const id=form.dataset.id,b=Object.fromEntries(new FormData(form));try{const out=await api("help/"+id,"PUT",b);msg($("#helpMsg"+id),out.message);load("help")}catch(x){msg($("#helpMsg"+id),x.message,true)}});
return;
}
if(tab==="staff"){const d=await api("staff");c.innerHTML='<div class="panel"><h2>Create staff account</h2><form id="staffForm" class="grid"><div><label>Name</label><input name="name" required></div><div><label>Email</label><input name="email" type="email" required></div><div><label>Temporary password (12+ characters)</label><input name="password" type="password" minlength="12" required></div><div><label>Role</label><select name="role"><option>technician</option><option>viewer</option><option>admin</option></select></div><div><button class="btn primary">Create account</button></div></form><div id="staffMsg"></div></div><div class="panel"><h2>Current staff</h2>'+table(d.items,["name","email","role","active","created_at"])+'</div>';$("#staffForm").onsubmit=async e=>{e.preventDefault();try{const d=await api("staff","POST",Object.fromEntries(new FormData(e.target)));msg($("#staffMsg"),d.message);load("staff")}catch(x){msg($("#staffMsg"),x.message,true)}};return}
const d=await api(tab);const cfg=defs[tab];c.innerHTML=(me.role!=="viewer"?formHtml(tab):"")+'<div class="panel"><h2>Registered '+cfg.title.toLowerCase()+'</h2>'+table(d.items,cfg.cols)+'</div>';
if($("#recordForm"))$("#recordForm").onsubmit=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));try{const d=await api(tab,"POST",b);msg($("#formMsg"),d.message);e.target.reset();load(tab)}catch(x){msg($("#formMsg"),x.message,true)}};
}catch(e){c.innerHTML='<div class="notice error">'+esc(e.message)+'</div>'}}
function table(rows,cols){if(!rows?.length)return '<p class="muted">No records found yet.</p>';return '<div class="tablewrap"><table><thead><tr>'+cols.map(k=>'<th>'+k.replace(/_/g," ")+'</th>').join("")+'</tr></thead><tbody>'+rows.map(r=>'<tr>'+cols.map(k=>'<td>'+esc(r[k]??"—")+'</td>').join("")+'</tr>').join("")+'</tbody></table></div>'}
init();
</script></body></html>`;
}

export default {
 async fetch(request, env) {
  const headers = {
   "x-content-type-options":"nosniff",
   "x-frame-options":"DENY",
   "referrer-policy":"strict-origin-when-cross-origin",
   "permissions-policy":"camera=(), microphone=(), geolocation=()",
   "content-security-policy":"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
  };
  try {
   const url=new URL(request.url);
   if(url.pathname.startsWith("/api/")) {
    if(!env.DB) return json({error:"D1 database binding is missing. Configure the DB binding in Cloudflare."},500,headers);
    const response=await api(request,env);
    const h=new Headers(response.headers); for(const [k,v] of Object.entries(headers))h.set(k,v);
    return new Response(response.body,{status:response.status,headers:h});
   }
   if(request.method!=="GET" && request.method!=="HEAD") return new Response("Method not allowed",{status:405,headers});
   return new Response(page(),{headers:{"content-type":"text/html; charset=utf-8","cache-control":"no-store",...headers}});
  } catch (e) {
   console.error("Gaston Power Worker request failed:", e?.stack || e?.message || String(e));
   return json({error:"The request could not be completed."},500,headers);
  }
 }
};
