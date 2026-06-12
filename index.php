<?php
declare(strict_types=1);

$today = date('l, d M Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123e35">
    <title>WorkPulse | Workforce Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #18312b;
            --muted: #72817d;
            --paper: #f5f6f2;
            --white: #fff;
            --green: #123e35;
            --green-2: #1d584b;
            --lime: #c7f36b;
            --lime-soft: #eef9d8;
            --line: #e4e8e2;
            --orange: #ef8f48;
            --red: #df625c;
            --blue: #5a82db;
            --shadow: 0 14px 38px rgba(25, 49, 43, .07);
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DM Sans", sans-serif; background: var(--paper); color: var(--ink); }
        button, input, select { font: inherit; }
        button { cursor: pointer; }
        .app { min-height: 100vh; display: grid; grid-template-columns: 250px 1fr; }
        .sidebar { background: var(--green); color: #fff; padding: 28px 20px; position: fixed; inset: 0 auto 0 0; width: 250px; display: flex; flex-direction: column; z-index: 20; }
        .brand { display: flex; align-items: center; gap: 11px; padding: 0 9px 28px; font-family: Manrope, sans-serif; font-weight: 800; font-size: 21px; }
        .brand-mark { width: 36px; height: 36px; border-radius: 11px; background: var(--lime); display: grid; place-items: center; color: var(--green); }
        .brand-mark svg { width: 21px; }
        .nav-label { color: #8dac9f; font-size: 10px; font-weight: 700; letter-spacing: 1.4px; padding: 5px 13px 9px; }
        .nav { display: grid; gap: 5px; }
        .nav button { border: 0; color: #b9cdc5; background: transparent; padding: 11px 13px; border-radius: 10px; display: flex; align-items: center; gap: 12px; text-align: left; font-weight: 600; font-size: 14px; }
        .nav button:hover, .nav button.active { color: #fff; background: rgba(255,255,255,.1); }
        .nav button.active { box-shadow: inset 3px 0 var(--lime); }
        .nav svg { width: 19px; height: 19px; }
        .side-help { margin-top: auto; background: #1b4b40; padding: 17px; border-radius: 14px; }
        .side-help strong { display: block; font-size: 13px; margin-bottom: 5px; }
        .side-help span { font-size: 11px; line-height: 1.5; color: #a9c5ba; }
        .side-help button { margin-top: 12px; width: 100%; border: 0; background: var(--lime); color: var(--green); padding: 9px; border-radius: 8px; font-size: 12px; font-weight: 700; }
        .main { grid-column: 2; min-width: 0; }
        .topbar { height: 79px; display: flex; align-items: center; justify-content: space-between; padding: 0 34px; border-bottom: 1px solid var(--line); background: rgba(245,246,242,.9); backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 10; }
        .mobile-menu { display: none; border: 0; background: none; color: var(--ink); }
        .search { width: min(360px, 35vw); position: relative; }
        .search svg { position: absolute; width: 18px; left: 13px; top: 11px; color: var(--muted); }
        .search input { width: 100%; border: 1px solid var(--line); background: #fff; border-radius: 11px; padding: 10px 12px 10px 41px; outline: none; }
        .profile { display: flex; align-items: center; gap: 13px; }
        .icon-btn { position: relative; width: 39px; height: 39px; display: grid; place-items: center; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink); }
        .icon-btn svg { width: 18px; }
        .dot { position: absolute; top: 7px; right: 7px; width: 7px; height: 7px; border-radius: 99px; background: var(--orange); border: 1px solid #fff; }
        .avatar { width: 41px; height: 41px; border-radius: 12px; background: #d8e6df; display: grid; place-items: center; font-weight: 700; color: var(--green); }
        .profile-text strong, .profile-text span { display: block; }
        .profile-text strong { font-size: 13px; }.profile-text span { font-size: 11px; color: var(--muted); margin-top: 2px; }
        .content { padding: 30px 34px 48px; max-width: 1600px; margin: auto; }
        .page { display: none; }.page.active { display: block; animation: rise .25s ease; }
        @keyframes rise { from { opacity: 0; transform: translateY(6px); } }
        .heading { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 25px; }
        h1, h2, h3 { font-family: Manrope, sans-serif; margin: 0; }
        h1 { font-size: 27px; letter-spacing: -.7px; } h2 { font-size: 17px; } h3 { font-size: 14px; }
        .heading p { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
        .date-pill { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 10px 13px; color: var(--muted); font-size: 12px; white-space: nowrap; }
        .primary-btn { border: 0; border-radius: 9px; background: var(--green); color: #fff; padding: 10px 15px; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 7px; }
        .primary-btn:hover { background: var(--green-2); }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 17px; margin-bottom: 18px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 15px; box-shadow: var(--shadow); }
        .stat { padding: 19px; position: relative; overflow: hidden; }
        .stat-top { display: flex; align-items: center; justify-content: space-between; }
        .stat-icon { width: 39px; height: 39px; border-radius: 11px; display: grid; place-items: center; }
        .stat-icon svg { width: 20px; }.stat-icon.green { background: var(--lime-soft); color: var(--green); }.stat-icon.orange { background: #fff0e5; color: #c86f31; }.stat-icon.blue { background: #eaf0ff; color: var(--blue); }.stat-icon.red { background: #fdeceb; color: var(--red); }
        .trend { font-size: 10px; font-weight: 700; color: #2c846a; background: #eaf7f1; padding: 4px 6px; border-radius: 5px; }
        .stat .value { font-family: Manrope, sans-serif; font-size: 26px; font-weight: 800; margin-top: 15px; }.stat .label { font-size: 12px; color: var(--muted); margin-top: 4px; }
        .grid-main { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(270px, .9fr); gap: 18px; margin-bottom: 18px; }
        .panel { padding: 21px; }
        .panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 19px; }
        .panel-head a { color: var(--green-2); font-size: 11px; font-weight: 700; text-decoration: none; }
        .project-row { display: grid; grid-template-columns: 1.2fr .75fr .7fr .5fr; align-items: center; gap: 12px; padding: 13px 0; border-top: 1px solid #eef0ed; font-size: 12px; }
        .project-row.header { color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: .5px; border: 0; padding-top: 0; }
        .project-name { display: flex; align-items: center; gap: 10px; font-weight: 700; }
        .project-initial { width: 33px; height: 33px; border-radius: 9px; display: grid; place-items: center; background: #edf3ef; color: var(--green); font-weight: 800; }
        .people { display: flex; }.mini-avatar { width: 26px; height: 26px; border-radius: 50%; border: 2px solid #fff; background: #d6e4dd; margin-left: -6px; display: grid; place-items: center; font-size: 8px; font-weight: 700; }.mini-avatar:first-child { margin-left: 0; }
        .status { display: inline-flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; }.status:before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: #39a77e; }.status.warning:before { background: var(--orange); }
        .attendance-ring { width: 150px; height: 150px; border-radius: 50%; margin: 12px auto 18px; background: conic-gradient(var(--green) 0 78%, #edf0ec 78% 100%); display: grid; place-items: center; position: relative; }
        .attendance-ring:after { content: ""; width: 115px; height: 115px; border-radius: 50%; background: #fff; position: absolute; }
        .ring-label { position: relative; z-index: 1; text-align: center; }.ring-label strong { font-family: Manrope; font-size: 25px; display: block; }.ring-label span { font-size: 10px; color: var(--muted); }
        .legend { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }.legend-item { background: #f7f8f5; border-radius: 9px; padding: 10px; font-size: 10px; color: var(--muted); }.legend-item strong { display: block; color: var(--ink); font-size: 15px; margin-top: 3px; }
        .bottom-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 18px; }
        .activity { display: flex; gap: 12px; padding: 12px 0; border-top: 1px solid #eef0ed; }.activity:first-of-type { border-top: 0; }.activity-icon { flex: 0 0 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; background: var(--lime-soft); color: var(--green); }.activity-icon svg { width: 16px; }.activity p { margin: 0; font-size: 11px; line-height: 1.5; }.activity p strong { font-size: 12px; }.activity time { font-size: 9px; color: var(--muted); }
        .pay-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-top: 1px solid #eef0ed; }.pay-row:first-of-type { border: 0; }.pay-info { flex: 1; }.pay-info strong { display: block; font-size: 12px; }.pay-info span { font-size: 10px; color: var(--muted); }.amount { font-family: Manrope; font-weight: 800; font-size: 13px; }
        .table-card { overflow: hidden; }.toolbar { padding: 16px 19px; display: flex; gap: 10px; justify-content: space-between; border-bottom: 1px solid var(--line); }.toolbar input, .toolbar select { border: 1px solid var(--line); border-radius: 9px; padding: 9px 11px; background: #fff; color: var(--ink); font-size: 12px; outline: none; }.toolbar input { min-width: 240px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; } th { text-align: left; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; font-size: 9px; padding: 12px 18px; background: #fafbf9; } td { padding: 14px 18px; border-top: 1px solid #eef0ed; } .employee-cell { display: flex; align-items: center; gap: 9px; }.employee-cell strong { display: block; }.employee-cell span { color: var(--muted); font-size: 10px; }.badge { display: inline-block; padding: 5px 8px; border-radius: 6px; font-size: 9px; font-weight: 700; background: var(--lime-soft); color: var(--green); }.badge.orange { background: #fff0e5; color: #b96329; }.badge.red { background: #fdeceb; color: #bd4e49; }
        .attendance-layout { display: grid; grid-template-columns: 1.2fr .8fr; gap: 18px; }.camera-box { min-height: 390px; border-radius: 15px; background: linear-gradient(145deg, #173f37, #0d2f28); color: #fff; display: grid; place-items: center; text-align: center; padding: 30px; position: relative; overflow: hidden; }.camera-box:before, .camera-box:after { content: ""; position: absolute; width: 150px; height: 150px; border: 1px solid rgba(199,243,107,.2); border-radius: 50%; }.camera-box:before { top: -40px; right: -30px; }.camera-box:after { bottom: -50px; left: -30px; }.face-frame { width: 170px; height: 210px; border: 2px solid var(--lime); border-radius: 48% 48% 42% 42%; margin: 0 auto 18px; position: relative; box-shadow: 0 0 35px rgba(199,243,107,.18); }.face-frame i { position: absolute; width: 18px; height: 18px; border-color: #fff; }.camera-box h2 { font-size: 19px; margin-bottom: 7px; }.camera-box p { color: #a9c5ba; font-size: 12px; max-width: 320px; line-height: 1.6; }.camera-box button { margin-top: 13px; border: 0; border-radius: 9px; background: var(--lime); color: var(--green); padding: 11px 18px; font-weight: 800; }.geo-card { padding: 21px; }.map { height: 170px; border-radius: 12px; background-color: #e7eee9; background-image: linear-gradient(30deg, #d8e5dd 12%, transparent 12.5%, transparent 87%, #d8e5dd 87.5%), linear-gradient(150deg, #d8e5dd 12%, transparent 12.5%, transparent 87%, #d8e5dd 87.5%); background-size: 48px 84px; display: grid; place-items: center; position: relative; margin-bottom: 16px; }.pin { width: 48px; height: 48px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); background: var(--green); display: grid; place-items: center; box-shadow: 0 8px 20px rgba(18,62,53,.25); }.pin svg { color: var(--lime); width: 21px; transform: rotate(45deg); }.geo-row { display: flex; justify-content: space-between; padding: 11px 0; border-bottom: 1px solid #eef0ed; font-size: 11px; }.geo-row span { color: var(--muted); }.within { color: #278263 !important; font-weight: 700; }
        .incentive-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 17px; }.incentive { padding: 22px; }.incentive .role-icon { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--lime-soft); color: var(--green); margin-bottom: 17px; }.role-icon svg { width: 22px; }.incentive p { color: var(--muted); font-size: 11px; line-height: 1.6; min-height: 35px; }.slab { display: flex; justify-content: space-between; padding: 11px 0; border-top: 1px solid #eef0ed; font-size: 11px; }.slab strong { font-family: Manrope; }.total-strip { grid-column: 1 / -1; background: var(--green); color: #fff; padding: 22px 25px; border-radius: 15px; display: flex; justify-content: space-between; align-items: center; }.total-strip span { color: #a9c5ba; font-size: 11px; }.total-strip strong { display: block; font-size: 24px; margin-top: 3px; }.toast { position: fixed; right: 24px; bottom: 24px; background: var(--green); color: #fff; padding: 13px 17px; border-radius: 10px; font-size: 12px; box-shadow: 0 12px 30px rgba(0,0,0,.18); transform: translateY(90px); opacity: 0; transition: .25s; z-index: 99; }.toast.show { transform: translateY(0); opacity: 1; }
        @media (max-width: 1050px) { .stats { grid-template-columns: repeat(2, 1fr); }.grid-main, .bottom-grid { grid-template-columns: 1fr; }.incentive-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 760px) { .app { display: block; }.sidebar { transform: translateX(-100%); transition: .25s; box-shadow: 20px 0 40px rgba(0,0,0,.18); }.sidebar.open { transform: translateX(0); }.main { width: 100%; }.topbar { padding: 0 17px; height: 68px; }.mobile-menu { display: block; }.search { display: none; }.profile-text { display: none; }.content { padding: 22px 16px 40px; }.heading { align-items: center; }.date-pill { display: none; }.stats { gap: 10px; }.stat { padding: 15px; }.stat .value { font-size: 22px; }.project-row { grid-template-columns: 1.3fr .8fr .6fr; }.project-row > :nth-child(3) { display: none; }.attendance-layout { grid-template-columns: 1fr; }.incentive-grid { grid-template-columns: 1fr; }.toolbar { flex-wrap: wrap; }.toolbar input { width: 100%; min-width: 0; }.table-wrap { overflow-x: auto; } table { min-width: 720px; } }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="brand"><span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M4 13h5l2-8 3 14 2-6h4"/></svg></span>WorkPulse</div>
        <div class="nav-label">WORKSPACE</div>
        <nav class="nav">
            <button class="active" data-page="dashboard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>Dashboard</button>
            <button data-page="attendance"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg>Attendance</button>
            <button data-page="employees"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>Employees</button>
            <button data-page="projects"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h6"/></svg>Projects</button>
            <button data-page="payroll"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M7 15h2"/></svg>Payroll & Advances</button>
            <button data-page="incentives"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M8.5 13 7 22l5-3 5 3-1.5-9"/></svg>Incentives</button>
            <button data-page="reports"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18M7 16l4-5 3 3 5-7"/></svg>Reports</button>
        </nav>
        <div class="side-help"><strong>Need help?</strong><span>Get support with attendance, payroll or incentive setup.</span><button onclick="notify('Support request created')">Contact Support</button></div>
    </aside>
    <main class="main">
        <header class="topbar">
            <button class="mobile-menu" onclick="toggleMenu()" aria-label="Open menu"><svg width="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            <div class="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><input id="globalSearch" placeholder="Search employees, projects..." aria-label="Search"></div>
            <div class="profile"><button class="icon-btn" onclick="notify('No new urgent notifications')" aria-label="Notifications"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M13.7 21h-3.4"/></svg><i class="dot"></i></button><div class="avatar">AK</div><div class="profile-text"><strong>Admin Kumar</strong><span>Super Admin</span></div></div>
        </header>
        <div class="content">
            <section class="page active" id="dashboard">
                <div class="heading"><div><h1>Good morning, Admin</h1><p>Here is what is happening across your workforce today.</p></div><div class="date-pill"><?= htmlspecialchars($today, ENT_QUOTES) ?></div></div>
                <div class="stats">
                    <article class="card stat"><div class="stat-top"><span class="stat-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg></span><span class="trend">+2 this month</span></div><div class="value">12</div><div class="label">Total employees</div></article>
                    <article class="card stat"><div class="stat-top"><span class="stat-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14"/></svg></span><span class="trend">All active</span></div><div class="value">5</div><div class="label">Active projects</div></article>
                    <article class="card stat"><div class="stat-top"><span class="stat-icon orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span class="trend">78% checked in</span></div><div class="value">9</div><div class="label">Present today</div></article>
                    <article class="card stat"><div class="stat-top"><span class="stat-icon red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h12v20l-6-3-6 3V2Z"/></svg></span><span class="trend">This month</span></div><div class="value">₹48.7K</div><div class="label">Incentives earned</div></article>
                </div>
                <div class="grid-main">
                    <article class="card panel"><div class="panel-head"><h2>Project Overview</h2><a href="#" onclick="goTo('projects');return false">View all projects →</a></div><div class="project-row header"><span>Project</span><span>Team</span><span>Attendance</span><span>Status</span></div>
                        <div class="project-row"><div class="project-name"><i class="project-initial">GS</i><span>Green Square</span></div><div class="people"><i class="mini-avatar">RK</i><i class="mini-avatar">AS</i><i class="mini-avatar">+1</i></div><span>3 / 3 present</span><span class="status">On track</span></div>
                        <div class="project-row"><div class="project-name"><i class="project-initial">RH</i><span>River Heights</span></div><div class="people"><i class="mini-avatar">NS</i><i class="mini-avatar">PK</i></div><span>2 / 2 present</span><span class="status">On track</span></div>
                        <div class="project-row"><div class="project-name"><i class="project-initial">OC</i><span>Orchid County</span></div><div class="people"><i class="mini-avatar">VM</i><i class="mini-avatar">JT</i></div><span>1 / 2 present</span><span class="status warning">Attention</span></div>
                        <div class="project-row"><div class="project-name"><i class="project-initial">SP</i><span>Skyline Plaza</span></div><div class="people"><i class="mini-avatar">AP</i><i class="mini-avatar">RD</i></div><span>2 / 2 present</span><span class="status">On track</span></div>
                        <div class="project-row"><div class="project-name"><i class="project-initial">PV</i><span>Palm Vista</span></div><div class="people"><i class="mini-avatar">MK</i><i class="mini-avatar">+2</i></div><span>1 / 3 present</span><span class="status warning">Attention</span></div>
                    </article>
                    <article class="card panel"><div class="panel-head"><h2>Today's Attendance</h2><a href="#" onclick="goTo('attendance');return false">Details →</a></div><div class="attendance-ring"><div class="ring-label"><strong>78%</strong><span>Attendance rate</span></div></div><div class="legend"><div class="legend-item">Present<strong>9</strong></div><div class="legend-item">Absent / Late<strong>3</strong></div></div></article>
                </div>
                <div class="bottom-grid">
                    <article class="card panel"><div class="panel-head"><h2>Recent Activity</h2><a href="#">View all →</a></div><div class="activity"><i class="activity-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></i><p><strong>Rakesh Kumar checked in</strong><br>Face verified at Green Square · within 42m<br><time>8 minutes ago</time></p></div><div class="activity"><i class="activity-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></i><p><strong>Advance approved for Neha</strong><br>₹5,000 will be adjusted in June payroll<br><time>35 minutes ago</time></p></div><div class="activity"><i class="activity-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg></i><p><strong>Visit milestone achieved</strong><br>Anil completed 30 visits and earned ₹750<br><time>1 hour ago</time></p></div></article>
                    <article class="card panel"><div class="panel-head"><h2>June Payroll Snapshot</h2><a href="#" onclick="goTo('payroll');return false">Open payroll →</a></div><div class="pay-row"><div class="pay-info"><strong>Gross salary</strong><span>12 employees</span></div><div class="amount">₹3,84,000</div></div><div class="pay-row"><div class="pay-info"><strong>Incentives</strong><span>Visits, role and deal share</span></div><div class="amount">+ ₹48,700</div></div><div class="pay-row"><div class="pay-info"><strong>Advance deductions</strong><span>4 active advances</span></div><div class="amount">− ₹24,000</div></div><div class="pay-row"><div class="pay-info"><strong>Estimated payout</strong><span>Due on 30 June</span></div><div class="amount">₹4,08,700</div></div></article>
                </div>
            </section>

            <section class="page" id="attendance"><div class="heading"><div><h1>Face Attendance</h1><p>Verify identity and project location before marking attendance.</p></div><button class="primary-btn" onclick="notify('Attendance report exported')">Export Report</button></div><div class="attendance-layout"><div class="camera-box"><div><div class="face-frame"></div><h2>Ready for face verification</h2><p>Employee must be within the 100-metre project radius. GPS and time are saved with every check-in.</p><button onclick="simulateScan(this)">Start Face Scan</button></div></div><article class="card geo-card"><div class="panel-head"><h2>Location Verification</h2><span class="badge">GPS Active</span></div><div class="map"><div class="pin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2"/></svg></div></div><div class="geo-row"><span>Assigned project</span><strong>Green Square</strong></div><div class="geo-row"><span>Allowed radius</span><strong>100 metres</strong></div><div class="geo-row"><span>Current distance</span><span class="within">42m · Within range</span></div><div class="geo-row"><span>Shift timing</span><strong>09:00 - 18:00</strong></div></article></div></section>

            <section class="page" id="employees"><div class="heading"><div><h1>Employees</h1><p>Manage roles, project assignments and attendance profiles.</p></div><button class="primary-btn" onclick="notify('Employee form is ready')">+ Add Employee</button></div><div class="card table-card"><div class="toolbar"><input class="table-search" placeholder="Search employee..."><select><option>All projects</option><option>Green Square</option><option>River Heights</option></select></div><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Role</th><th>Project</th><th>Monthly salary</th><th>Face profile</th><th>Status</th></tr></thead><tbody>
                <tr><td><div class="employee-cell"><i class="mini-avatar">RK</i><span><strong>Rakesh Kumar</strong>EMP-001</span></div></td><td>Sales Executive</td><td>Green Square</td><td>₹32,000</td><td><span class="badge">Registered</span></td><td><span class="status">Active</span></td></tr>
                <tr><td><div class="employee-cell"><i class="mini-avatar">AS</i><span><strong>Anil Sharma</strong>EMP-002</span></div></td><td>Telecaller</td><td>Green Square</td><td>₹22,000</td><td><span class="badge">Registered</span></td><td><span class="status">Active</span></td></tr>
                <tr><td><div class="employee-cell"><i class="mini-avatar">NS</i><span><strong>Neha Singh</strong>EMP-003</span></div></td><td>Sales Executive</td><td>River Heights</td><td>₹30,000</td><td><span class="badge">Registered</span></td><td><span class="status">Active</span></td></tr>
                <tr><td><div class="employee-cell"><i class="mini-avatar">VM</i><span><strong>Vikram Mehta</strong>EMP-004</span></div></td><td>Senior Manager</td><td>All Projects</td><td>₹60,000</td><td><span class="badge">Registered</span></td><td><span class="status">Active</span></td></tr>
                <tr><td><div class="employee-cell"><i class="mini-avatar">JT</i><span><strong>Jyoti Thakur</strong>EMP-005</span></div></td><td>Telecaller</td><td>Orchid County</td><td>₹21,000</td><td><span class="badge orange">Pending</span></td><td><span class="status warning">Setup needed</span></td></tr>
            </tbody></table></div></div></section>

            <section class="page" id="projects"><div class="heading"><div><h1>Projects & Geofences</h1><p>Five active work locations with 100-metre attendance zones.</p></div><button class="primary-btn" onclick="notify('Project form is ready')">+ Add Project</button></div><div class="incentive-grid"><article class="card incentive"><h2>Green Square</h2><p>Sector 82, Gurugram · 3 employees</p><div class="slab"><span>Geofence</span><strong>100m</strong></div><div class="slab"><span>Today</span><strong>3 / 3 present</strong></div></article><article class="card incentive"><h2>River Heights</h2><p>Dwarka Expressway · 2 employees</p><div class="slab"><span>Geofence</span><strong>100m</strong></div><div class="slab"><span>Today</span><strong>2 / 2 present</strong></div></article><article class="card incentive"><h2>Orchid County</h2><p>Golf Course Road · 2 employees</p><div class="slab"><span>Geofence</span><strong>100m</strong></div><div class="slab"><span>Today</span><strong>1 / 2 present</strong></div></article><article class="card incentive"><h2>Skyline Plaza</h2><p>Sector 65, Gurugram · 2 employees</p><div class="slab"><span>Geofence</span><strong>100m</strong></div><div class="slab"><span>Today</span><strong>2 / 2 present</strong></div></article><article class="card incentive"><h2>Palm Vista</h2><p>New Gurgaon · 3 employees</p><div class="slab"><span>Geofence</span><strong>100m</strong></div><div class="slab"><span>Today</span><strong>1 / 3 present</strong></div></article></div></section>

            <section class="page" id="payroll"><div class="heading"><div><h1>Payroll & Advances</h1><p>June 2026 salary calculation with incentives and advance recovery.</p></div><button class="primary-btn" onclick="notify('Payroll calculation completed')">Run Payroll</button></div><div class="card table-card"><div class="toolbar"><input class="table-search" placeholder="Search payroll..."><select><option>June 2026</option><option>May 2026</option></select></div><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Base salary</th><th>Incentive</th><th>Advance</th><th>Net payable</th><th>Status</th></tr></thead><tbody><tr><td><strong>Rakesh Kumar</strong></td><td>₹32,000</td><td>₹1,500</td><td>₹0</td><td><strong>₹33,500</strong></td><td><span class="badge orange">Pending</span></td></tr><tr><td><strong>Anil Sharma</strong></td><td>₹22,000</td><td>₹1,750</td><td>− ₹2,000</td><td><strong>₹21,750</strong></td><td><span class="badge orange">Pending</span></td></tr><tr><td><strong>Neha Singh</strong></td><td>₹30,000</td><td>₹1,500</td><td>− ₹5,000</td><td><strong>₹26,500</strong></td><td><span class="badge orange">Pending</span></td></tr><tr><td><strong>Vikram Mehta</strong></td><td>₹60,000</td><td>₹24,000</td><td>₹0</td><td><strong>₹84,000</strong></td><td><span class="badge">Approved</span></td></tr></tbody></table></div></div></section>

            <section class="page" id="incentives"><div class="heading"><div><h1>Incentive Rules</h1><p>Transparent, role-based rewards calculated automatically each month.</p></div><button class="primary-btn" onclick="notify('Incentive rule form opened')">+ Add Rule</button></div><div class="incentive-grid"><article class="card incentive"><div class="role-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2.1Z"/></svg></div><h2>Telecaller</h2><p>Fixed role incentive plus rewards for verified project visits.</p><div class="slab"><span>Monthly role incentive</span><strong>₹1,000</strong></div><div class="slab"><span>20 verified visits</span><strong>₹500</strong></div><div class="slab"><span>30 verified visits</span><strong>₹750</strong></div></article><article class="card incentive"><div class="role-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18M7 16l4-5 3 3 5-7"/></svg></div><h2>Sales Executive</h2><p>Monthly incentive for active sales team members meeting targets.</p><div class="slab"><span>Monthly role incentive</span><strong>₹1,500</strong></div><div class="slab"><span>Target status</span><strong>Achieved</strong></div></article><article class="card incentive"><div class="role-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="5"/><path d="M8 13 6 22l6-3 6 3-2-9"/></svg></div><h2>Manager</h2><p>Monthly leadership incentive for managing assigned project teams.</p><div class="slab"><span>Monthly role incentive</span><strong>₹5,000</strong></div></article><article class="card incentive"><div class="role-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></div><h2>Senior Manager</h2><p>Deal incentive becomes payable when the service payment is received.</p><div class="slab"><span>Monthly on-account pay</span><strong>₹60,000</strong></div><div class="slab"><span>Deal amount share</span><strong>0.60%</strong></div><div class="slab"><span>Trigger</span><strong>On receipt</strong></div></article><div class="total-strip"><div><span>Estimated incentive payout · June 2026</span><strong>₹48,700</strong></div><button class="primary-btn" style="background:var(--lime);color:var(--green)" onclick="notify('Incentive sheet generated')">Generate Sheet</button></div></div></section>

            <section class="page" id="reports"><div class="heading"><div><h1>Reports</h1><p>Attendance, payroll, advances and incentive summaries.</p></div><button class="primary-btn" onclick="notify('Monthly MIS report exported')">Export Monthly MIS</button></div><div class="stats"><article class="card stat"><div class="value">93.4%</div><div class="label">Monthly attendance</div></article><article class="card stat"><div class="value">286</div><div class="label">Verified visits</div></article><article class="card stat"><div class="value">₹24K</div><div class="label">Outstanding advances</div></article><article class="card stat"><div class="value">₹48.7K</div><div class="label">Total incentives</div></article></div></section>
        </div>
    </main>
</div>
<div class="toast" id="toast"></div>
<script>
const navButtons = document.querySelectorAll('.nav button[data-page]');
const pages = document.querySelectorAll('.page');
function goTo(id) { pages.forEach(p => p.classList.toggle('active', p.id === id)); navButtons.forEach(b => b.classList.toggle('active', b.dataset.page === id)); document.getElementById('sidebar').classList.remove('open'); window.scrollTo({top: 0, behavior: 'smooth'}); }
navButtons.forEach(button => button.addEventListener('click', () => goTo(button.dataset.page)));
function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
function notify(message) { const toast = document.getElementById('toast'); toast.textContent = message; toast.classList.add('show'); clearTimeout(window.toastTimer); window.toastTimer = setTimeout(() => toast.classList.remove('show'), 2500); }
function simulateScan(button) { button.disabled = true; button.textContent = 'Scanning...'; setTimeout(() => { button.textContent = 'Face Verified ✓'; button.style.background = '#fff'; notify('Attendance marked at 09:12 · Green Square'); }, 1400); }
document.querySelectorAll('.table-search').forEach(input => input.addEventListener('input', function() { const term = this.value.toLowerCase(); const table = this.closest('.table-card').querySelector('table'); table.querySelectorAll('tbody tr').forEach(row => row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none'); }));
document.getElementById('globalSearch').addEventListener('keydown', function(e) { if (e.key === 'Enter') { goTo('employees'); const target = document.querySelector('#employees .table-search'); target.value = this.value; target.dispatchEvent(new Event('input')); } });
</script>
</body>
</html>
