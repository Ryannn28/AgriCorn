<?php
require_once __DIR__ . '/data/db_connect.php';
$farmerCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'farmer'");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $farmerCount = (int)$row['count'];
    }
    $stmt->close();
} catch (Exception $e) {
    $farmerCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
		<style>
			.faq-accordion {
				display: grid;
				grid-template-columns: repeat(2, 1fr);
				gap: 18px;
				align-items: start;
			}
			@media (max-width: 900px) {
				.faq-accordion {
					grid-template-columns: 1fr;
				}
			}
			.faq-item {
				background: #f7faf7;
				border-radius: 14px;
				box-shadow: 0 2px 8px rgba(46,139,87,0.04);
				overflow: hidden;
				border: 1.5px solid #e0e0e0;
			}
			.faq-question {
				cursor: pointer;
				padding: 22px 28px;
				font-weight: 700;
				color: #219150;
				font-size: 1.13rem;
				background: none;
				border: none;
				outline: none;
				width: 100%;
				text-align: left;
				transition: background 0.2s;
				display: flex;
				align-items: center;
				justify-content: space-between;
			}
			.faq-question:hover {
				background: #e8f4ea;
			}
			.faq-answer {
				padding: 0 28px 22px 28px;
				color: #444;
				font-size: 1.07rem;
				display: none;
				animation: fadeIn 0.3s;
			}
			.faq-item.active .faq-answer {
				display: block;
			}
			.faq-arrow {
				font-size: 1.3rem;
				transition: transform 0.2s;
			}
			.faq-item.active .faq-arrow {
				transform: rotate(90deg);
			}
			@keyframes fadeIn {
				from { opacity: 0; }
				to { opacity: 1; }
			}
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				// FAQ Toggle
				document.querySelectorAll('.faq-question').forEach(function(btn) {
					btn.addEventListener('click', function() {
						const item = btn.closest('.faq-item');
						const isOpen = item.classList.contains('active');
						
						// Close all other FAQ items
						document.querySelectorAll('.faq-item').forEach(other => {
							other.classList.remove('active');
						});
						
						// If the clicked item was NOT already open, open it
						if (!isOpen) {
							item.classList.add('active');
						}
					});
				});

				// Reveal Animations on Scroll
				const revealElements = document.querySelectorAll('.reveal');
				const revealObserver = new IntersectionObserver((entries, observer) => {
					entries.forEach(entry => {
						if(entry.isIntersecting) {
							entry.target.classList.add('active');
							// Optional: observer.unobserve(entry.target); to reveal only once
						}
					});
				}, { rootMargin: "0px 0px -50px 0px", threshold: 0.1 });
				revealElements.forEach(el => revealObserver.observe(el));

				// Back to Top Button
				const backToTopBtn = document.getElementById('backToTop');
				if (backToTopBtn) {
					window.addEventListener('scroll', () => {
						if (window.scrollY > 600) {
							backToTopBtn.classList.add('visible');
						} else {
							backToTopBtn.classList.remove('visible');
						}
					});
					backToTopBtn.addEventListener('click', () => {
						window.scrollTo({ top: 0, behavior: 'smooth' });
					});
				}
			});
		</script>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>AgriCorn Planner</title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
	<style>
			   /* Consistent section sizing */
			   .main-section {
				   max-width: 1200px;
				   min-height: 480px;
				   margin: 0 auto;
				   padding: 64px 24px 64px 24px;
				   display: flex;
				   flex-direction: column;
				   justify-content: center;
			   }
			   @media (max-width: 900px) {
				   .main-section {
					   min-height: 320px;
					   padding: 40px 12px 40px 12px;
				   }
			   }
			/* Animation Reveal Classes */
			.reveal {
				opacity: 0;
				transform: translateY(40px);
				transition: all 0.8s cubic-bezier(0.5, 0, 0, 1);
			}
			.reveal.active {
				opacity: 1;
				transform: translateY(0);
			}
			/* Back to Top Button */
			#backToTop {
				position: fixed;
				bottom: 30px;
				right: 30px;
				width: 50px;
				height: 50px;
				background: linear-gradient(135deg, #22c55e, #16a34a);
				color: white;
				border-radius: 50%;
				border: none;
				box-shadow: 0 4px 16px rgba(22, 163, 74, 0.4);
				display: flex;
				align-items: center;
				justify-content: center;
				cursor: pointer;
				opacity: 0;
				visibility: hidden;
				transform: translateY(20px);
				transition: all 0.3s ease;
				z-index: 999;
			}
			#backToTop.visible {
				opacity: 1;
				visibility: visible;
				transform: translateY(0);
			}
			#backToTop:hover {
				transform: translateY(-5px);
				box-shadow: 0 8px 24px rgba(22, 163, 74, 0.6);
			}
		html {
			scroll-behavior: smooth;
			overflow-x: hidden;
		}
		body {
			margin: 0;
			font-family: 'Inter', Arial, Helvetica, sans-serif;
			background: linear-gradient(rgba(240, 253, 244, 0.92), rgba(254, 252, 232, 0.92)), url('cornbg.jpg') center/cover fixed;
			color: #222;
			overflow-x: hidden;
		}
		.section-title {
			font-size: 3.2rem;
			font-weight: 800;
			letter-spacing: -0.5px;
			line-height: 1.15;
			color: #2f3e35;
			margin-bottom: 12px;
			text-align: center;
		}
		*, *::before, *::after {
			box-sizing: border-box;
		}
		img, svg {
			max-width: 100%;
		}
		   .navbar {
			   display: flex;
			   align-items: center;
			   justify-content: space-between;
			   padding: 18px 48px;
			   backdrop-filter: blur(8px);
			   -webkit-backdrop-filter: blur(8px);
			   background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));
			   border-bottom: 1px solid rgba(127, 182, 133, 0.3);
			   box-shadow: 0 4px 16px rgba(34, 58, 39, 0.08);
			   position: sticky;
			   top: 0;
			   z-index: 100;
			   transition: all .3s ease;
			gap: 18px;
		   }
		.navbar-toggle {
			display: none;
			align-items: center;
			justify-content: center;
			width: 46px;
			height: 46px;
			border: 1px solid rgba(33, 145, 80, 0.16);
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.7);
			color: #176b3a;
			cursor: pointer;
			box-shadow: 0 4px 14px rgba(34, 58, 39, 0.08);
			flex-shrink: 0;
		}
		.navbar-toggle svg {
			width: 22px;
			height: 22px;
		}
		.navbar-logo {
			display: flex;
			align-items: center;
			font-weight: 700;
			font-size: 1.5rem;
			color: #219150;
			gap: 0;
		}
		.navbar-logo span {
			color: #f6c941;
			font-weight: 500;
		}
		.navbar-menu a {
			color: #444;
			text-decoration: none;
			font-weight: 500;
			transition: color .2s;
			white-space: nowrap;
		}
		.navbar-menu a:hover {
			color: #219150;
		}
		.navbar-logo {
			flex: 1;
		}
		.navbar-menu {
			display: flex;
			gap: 24px;
			font-size: 0.95rem;
			flex: 2;
			justify-content: center;
		}
		.navbar-actions {
			flex: 1;
			display: flex;
			justify-content: flex-end;
		}
		.navbar-btn {
			background: linear-gradient(90deg, #21b36a 0%, #176b3a 100%);
			color: #ffffff !important;
			border: none;
			border-radius: 10px;
			padding: 10px 24px;
			font-size: 0.95rem;
			font-weight: 700;
			text-decoration: none;
			transition: transform .3s, box-shadow .3s;
			box-shadow: 0 4px 12px rgba(33, 179, 106, 0.2);
			white-space: nowrap;
		}
		.navbar-btn:hover {
			transform: translateY(-2px);
			box-shadow: 0 6px 16px rgba(33, 179, 106, 0.3);
		}
		@media (max-width: 1200px) {
			.navbar {
				flex-wrap: wrap;
				padding: 12px 24px;
			}
			.navbar-logo {
				flex: 1;
				order: 1;
			}
			.navbar-toggle {
				display: flex;
				order: 2;
				margin-left: 12px;
			}
			.navbar-actions {
				order: 3;
				flex: 0;
				margin-left: 12px;
			}
			.navbar-menu {
				display: none;
				width: 100%;
				order: 4;
				flex-direction: column;
				gap: 8px;
				padding: 16px 0;
				flex: none;
				justify-content: flex-start;
			}
			.navbar.is-open .navbar-menu {
				display: flex;
			}
			.navbar-menu a {
				width: 100%;
				padding: 12px;
				background: rgba(255, 255, 255, 0.5);
				border-radius: 8px;
				text-align: center;
			}
		}
		.navbar-menu a:focus-visible,
		.navbar-action:focus-visible,
		.navbar-toggle:focus-visible {
			outline: 3px solid rgba(33, 179, 106, 0.28);
			outline-offset: 2px;
		}
		   .navbar-action {
			   background: linear-gradient(90deg, #21b36a 60%, #f6c941 100%);
			   color: #fff;
			   border: none;
			   border-radius: 8px;
			   padding: 10px 28px;
			   font-weight: 700;
			   font-size: 1.1rem;
			   cursor: pointer;
			   transition: background .2s, transform .2s, box-shadow .2s;
			   box-shadow: 0 2px 8px rgba(46,139,87,0.08);
			   position: relative;
			   overflow: hidden;
		   }
		   .navbar-action:hover {
			   background: linear-gradient(90deg, #176b3a 60%, #f6c941 100%);
			   transform: translateY(-2px) scale(1.04);
			   box-shadow: 0 6px 24px rgba(46,139,87,0.13);
		   }
		.navbar.is-open .navbar-menu,
		.navbar.is-open .navbar-action {
			display: flex;
		}
		.hero {
			background: linear-gradient(rgba(20, 40, 20, 0.7), rgba(20, 40, 20, 0.85)), url('cornbg.jpg') center/cover no-repeat;
			padding: 100px 0 80px 0;
			text-align: center;
			position: relative;
			border-bottom: 1px solid rgba(255,255,255,0.1);
			max-width: 100% !important; /* Forces hero to span full screen width */
		}
		.hero-badge {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			align-self: center; /* Prevents stretching in flex column */
			background: rgba(33, 145, 80, 0.65); /* Made it greener */
			color: #ffffff;
			font-weight: 600;
			border-radius: 999px;
			padding: 6px 16px;
			font-size: 0.85rem;
			margin-bottom: 18px;
			margin-top: 0;
			box-shadow: 0 4px 12px rgba(33, 145, 80, 0.3);
			border: 1px solid rgba(255, 255, 255, 0.4);
			gap: 6px;
			backdrop-filter: blur(4px);
			-webkit-backdrop-filter: blur(4px);
		}
		.hero-badge svg {
			width: 14px;
			height: 14px;
			fill: currentColor;
		}
		.hero-title {
			font-size: 4.5rem;
			font-weight: 700;
			margin: 0 0 16px 0;
			color: #ffffff;
			letter-spacing: -1px;
			text-shadow: 0 2px 10px rgba(0,0,0,0.5);
		}
		.hero-title span {
			background: linear-gradient(90deg, #4ade80, #fef08a);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
			text-fill-color: transparent;
			text-shadow: none;
		}
		.hero-desc {
			font-size: 1.25rem;
			color: #f0fdf4;
			max-width: 800px;
			margin: 0 auto 32px auto;
			font-weight: 400;
			line-height: 1.6;
			text-shadow: 0 2px 6px rgba(0,0,0,0.6);
		}
		   .hero-btn {
			   background: linear-gradient(90deg, #21b36a 0%, #176b3a 100%);
			   color: #ffffff;
			   border: none;
			   border-radius: 12px;
			   padding: 16px 42px;
			   font-size: 1.2rem;
			   font-weight: 700;
			   box-shadow: 0 8px 24px rgba(33, 179, 106, 0.4);
			   cursor: pointer;
			   transition: transform .3s, box-shadow .3s;
			   margin: 0 auto 40px auto;
			   display: inline-block;
			   text-decoration: none;
		   }
		   .hero-btn:hover {
			   transform: translateY(-4px);
			   box-shadow: 0 12px 32px rgba(33, 179, 106, 0.6);
			   color: #ffffff;
		   }
			   /* Section Divider */


			   /* Scroll to top button */
			   #scrollTopBtn {
				   display: none;
				   position: fixed;
				   bottom: 32px;
				   right: 32px;
				   z-index: 999;
				   background: linear-gradient(90deg, #21b36a 60%, #f6c941 100%);
				   color: #fff;
				   border: none;
				   border-radius: 50%;
				   width: 48px;
				   height: 48px;
				   font-size: 2rem;
				   box-shadow: 0 4px 16px rgba(46,139,87,0.18);
				   cursor: pointer;
				   transition: background .2s, transform .2s;
			   }
			   #scrollTopBtn:hover {
				   background: linear-gradient(90deg, #176b3a 60%, #f6c941 100%);
				   transform: scale(1.08);
			   }
		   .stats-row {
			   display: grid;
			   grid-template-columns: repeat(4, 1fr);
			   gap: 24px;
			   justify-content: center;
			   align-items: stretch;
			   margin: 70px auto 0 auto;
			   max-width: 1100px;
			   width: 100%;
			   padding-bottom: 20px;
		   }
		   @media (max-width: 900px) {
			   .stats-row {
				   grid-template-columns: repeat(2, 1fr);
			   }
		   }
		   @media (max-width: 500px) {
			   .stats-row {
				   grid-template-columns: 1fr;
			   }
		   }
		   .stat-box {
			   background: rgba(255, 255, 255, 0.03);
			   border-radius: 16px;
			   box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
			   padding: 32px 20px;
			   text-align: center;
			   display: flex;
			   flex-direction: column;
			   align-items: center;
			   border: 1px solid rgba(255, 255, 255, 0.12);
			   backdrop-filter: blur(12px);
			   -webkit-backdrop-filter: blur(12px);
			   transition: box-shadow .3s, transform .3s, background .3s;
		   }
		   .stat-box:hover {
			   box-shadow: 0 16px 48px rgba(0, 0, 0, 0.3);
			   transform: translateY(-6px);
			   background: rgba(255, 255, 255, 0.08);
		   }
		.stat-icon {
			width: 28px;
			height: 28px;
			margin-bottom: 16px;
			fill: none;
			stroke: #4ade80;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}
		.stat-value {
			font-size: 2.6rem;
			font-weight: 700;
			margin-bottom: 4px;
			background: linear-gradient(90deg, #4ade80, #fef08a);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
			text-fill-color: transparent;
			line-height: 1.2;
		}
		.stat-label {
			font-size: 1rem;
			color: #e2e8f0;
			font-weight: 500;
		}
		/* Why Choose Section */
		   .why-section-container {
			   background: rgba(240, 253, 244, 0.85);
			   border-radius: 32px;
			   padding: 64px 40px;
			   margin: 40px auto 20px auto;
			   max-width: 1000px;
			   box-shadow: 0 8px 32px rgba(0, 0, 0, 0.04);
			   border: 1px solid rgba(255, 255, 255, 0.8);
		   }
		   .why-title {
			   font-size: 3.2rem;
			   font-weight: 800;
			   text-align: center;
			   color: #2f3e35;
			   margin-bottom: 12px;
			   letter-spacing: -0.5px;
		   }
		   .why-title span {
			   background: linear-gradient(90deg, #22c55e, #eab308);
			   -webkit-background-clip: text;
			   -webkit-text-fill-color: transparent;
			   background-clip: text;
			   text-fill-color: transparent;
		   }
		   .why-subtitle {
			   text-align: center;
			   font-size: 1.15rem;
			   color: #666;
			   margin-bottom: 48px;
		   }
		   .why-grid {
			   display: grid;
			   grid-template-columns: repeat(2, 1fr);
			   gap: 24px;
			   margin-bottom: 48px;
		   }
		   @media (max-width: 768px) {
			   .why-grid {
				   grid-template-columns: 1fr;
			   }
		   }
		   .why-card {
			   background: #ffffff;
			   border-radius: 16px;
			   padding: 24px;
			   display: flex;
			   align-items: flex-start;
			   gap: 16px;
			   box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
			   border: 1px solid rgba(0, 0, 0, 0.02);
		   }
		   .why-icon {
			   min-width: 24px;
			   width: 24px;
			   height: 24px;
			   color: #22c55e;
			   margin-top: 2px;
		   }
		   .why-text {
			   font-size: 1.05rem;
			   color: #444;
			   line-height: 1.5;
			   font-weight: 500;
		   }
		   .why-btn {
			   background: linear-gradient(90deg, #21b36a 0%, #176b3a 100%);
			   color: #ffffff;
			   border: none;
			   border-radius: 12px;
			   padding: 16px 36px;
			   font-size: 1.1rem;
			   font-weight: 700;
			   display: block;
			   margin: 0 auto;
			   width: max-content;
			   text-decoration: none;
			   transition: transform .3s, box-shadow .3s;
			   box-shadow: 0 8px 24px rgba(33, 179, 106, 0.3);
		   }
		   .why-btn:hover {
			   transform: translateY(-3px);
			   box-shadow: 0 12px 28px rgba(33, 179, 106, 0.5);
			   color: #ffffff;
		   }
		/* How It Works Section */
		.how-section {
			text-align: center;
			padding-bottom: 40px;
			background: transparent;
		}
		.how-title {
			font-size: 3.2rem;
			font-weight: 800;
			color: #2f3e35;
			margin-bottom: 12px;
			letter-spacing: -0.5px;
		}
		.how-subtitle {
			font-size: 1.15rem;
			color: #666;
			margin-bottom: 48px;
		}
		.how-grid {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 32px;
			max-width: 1100px;
			margin: 0 auto;
			position: relative;
		}
		@media (max-width: 900px) {
			.how-grid {
				grid-template-columns: 1fr;
				gap: 48px;
			}
		}
		.how-card {
			background: #ffffff;
			border-radius: 20px;
			padding: 40px 32px;
			box-shadow: 0 4px 24px rgba(0,0,0,0.03);
			border: 1px solid rgba(0,0,0,0.02);
			display: flex;
			flex-direction: column;
			align-items: center;
			position: relative;
		}
		.how-step-num {
			width: 54px;
			height: 54px;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			color: #fff;
			font-size: 1.4rem;
			font-weight: 700;
			margin-bottom: 24px;
			box-shadow: 0 4px 12px rgba(0,0,0,0.1);
		}
		.how-step-num.green {
			background: linear-gradient(135deg, #4ade80, #22c55e);
		}
		.how-step-num.yellow {
			background: linear-gradient(135deg, #fbbf24, #f59e0b);
		}
		.how-icon-box {
			width: 64px;
			height: 64px;
			border-radius: 16px;
			display: flex;
			align-items: center;
			justify-content: center;
			margin-bottom: 24px;
		}
		.how-icon-box.green {
			background: #f0fdf4;
			color: #22c55e;
		}
		.how-icon-box.yellow {
			background: #fffbeb;
			color: #f59e0b;
		}
		.how-card-title {
			font-size: 1.35rem;
			font-weight: 700;
			color: #222;
			margin-bottom: 12px;
		}
		.how-card-text {
			font-size: 1.05rem;
			color: #666;
			line-height: 1.6;
		}
		/* Advanced Tech Section */
		.tech-section {
			padding: 80px 24px;
			background: transparent;
		}
		.tech-container {
			max-width: 1100px;
			margin: 0 auto;
		}
		.tech-title {
			text-align: center;
			font-size: 3.2rem;
			font-weight: 800;
			color: #2f3e35;
			margin-bottom: 12px;
			letter-spacing: -0.5px;
		}
		.tech-title span {
			background: linear-gradient(90deg, #4ade80, #fde047);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
			text-fill-color: transparent;
		}
		.tech-subtitle {
			text-align: center;
			font-size: 1.15rem;
			color: #666;
			margin-bottom: 64px;
		}
		.tech-grid {
			display: grid;
			grid-template-columns: repeat(2, 1fr);
			gap: 32px;
			align-items: start;
		}
		@media (max-width: 900px) {
			.tech-grid {
				grid-template-columns: 1fr;
			}
		}
		.tech-card {
			background: #ffffff;
			border-radius: 20px;
			padding: 40px;
			box-shadow: 0 4px 24px rgba(0,0,0,0.03);
			border: 1px solid rgba(0,0,0,0.02);
			display: flex;
			gap: 24px;
			align-items: flex-start;
		}
		.tech-icon-box {
			width: 64px;
			height: 64px;
			border-radius: 16px;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
			box-shadow: 0 4px 12px rgba(0,0,0,0.05);
		}
		.tech-icon-box.yellow {
			background: #fef9c3;
			color: #854d0e;
		}
		.tech-icon-box.green {
			background: #dcfce7;
			color: #166534;
		}
		.tech-card-content {
			flex: 1;
		}
		.tech-card-title {
			font-size: 1.35rem;
			font-weight: 700;
			color: #1f2937;
			margin-bottom: 16px;
		}
		.tech-card-text {
			font-size: 1.05rem;
			color: #6b7280;
			line-height: 1.6;
			margin-bottom: 24px;
		}
		.tech-list {
			list-style: none;
			padding: 0;
			margin: 0;
		}
		.tech-list li {
			display: flex;
			align-items: center;
			gap: 12px;
			font-size: 1rem;
			color: #4b5563;
			margin-bottom: 12px;
		}
		.tech-list li svg {
			width: 18px;
			height: 18px;
			flex-shrink: 0;
		}
		/* Built For Section */
		.built-section {
			padding: 100px 24px;
			background: transparent;
			position: relative;
			border-top: 1px solid rgba(0,0,0,0.03);
			border-bottom: 1px solid rgba(0,0,0,0.03);
		}
		.built-container {
			max-width: 1100px;
			margin: 0 auto;
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 64px;
			align-items: center;
		}
		@media (max-width: 900px) {
			.built-container {
				grid-template-columns: 1fr;
			}
		}
		.built-badge {
			display: inline-flex;
			padding: 6px 16px;
			background: rgba(34, 197, 94, 0.1);
			color: #16a34a;
			border-radius: 999px;
			font-size: 0.85rem;
			font-weight: 700;
			margin-bottom: 24px;
			border: 1px solid rgba(34, 197, 94, 0.2);
		}
		.built-title {
			font-size: 3.2rem;
			font-weight: 800;
			color: #1f2937;
			margin-bottom: 12px;
			line-height: 1.15;
			text-align: center;
			letter-spacing: -0.5px;
		}
		.built-title span {
			background: linear-gradient(90deg, #22c55e, #eab308);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
			text-fill-color: transparent;
		}
		.built-desc {
			font-size: 1.15rem;
			color: #4b5563;
			margin: 0 auto 56px auto;
			line-height: 1.6;
			text-align: center;
			max-width: 800px;
		}
		.built-features {
			display: flex;
			flex-direction: column;
			gap: 28px;
		}
		.b-feat-item {
			display: flex;
			gap: 16px;
			align-items: flex-start;
		}
		.b-feat-icon {
			width: 48px;
			height: 48px;
			border-radius: 12px;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}
		.b-feat-icon.green {
			background: #dcfce7;
			color: #166534;
		}
		.b-feat-icon.yellow {
			background: #fef9c3;
			color: #854d0e;
		}
		.b-feat-title {
			font-size: 1.15rem;
			font-weight: 700;
			color: #1f2937;
			margin-bottom: 4px;
		}
		.b-feat-text {
			font-size: 0.95rem;
			color: #6b7280;
			line-height: 1.5;
		}
		.built-stats-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 24px;
		}
		.b-stat-card {
			padding: 32px 24px;
			border-radius: 20px;
			border: 1px solid rgba(255, 255, 255, 0.4);
			box-shadow: 0 8px 32px rgba(0, 0, 0, 0.05);
			backdrop-filter: blur(8px);
			-webkit-backdrop-filter: blur(8px);
		}
		.b-stat-card.green {
			background: rgba(220, 252, 231, 0.6);
		}
		.b-stat-card.yellow {
			background: rgba(254, 249, 195, 0.6);
		}
		.b-stat-value {
			font-size: 2.8rem;
			font-weight: 800;
			margin-bottom: 8px;
		}
		.b-stat-card.green .b-stat-value {
			color: #16a34a;
		}
		.b-stat-card.yellow .b-stat-value {
			color: #d97706;
		}
		.b-stat-label {
			font-size: 0.95rem;
			color: #4b5563;
			font-weight: 500;
		}
		/* SDG Section */
		.sdg-section {
			padding: 80px 24px;
			background: transparent;
			text-align: center;
		}
		.sdg-title {
			font-size: 3.2rem;
			font-weight: 800;
			color: #2f3e35;
			margin-bottom: 12px;
			letter-spacing: -0.5px;
			max-width: 900px;
			margin-left: auto;
			margin-right: auto;
		}
		.sdg-title span {
			background: linear-gradient(90deg, #86efac, #fde047);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
			text-fill-color: transparent;
		}
		.sdg-subtitle {
			font-size: 1.15rem;
			color: #666;
			margin-bottom: 64px;
			max-width: 800px;
			margin-left: auto;
			margin-right: auto;
		}
		.sdg-grid {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 24px;
			max-width: 1200px;
			margin: 0 auto;
		}
		@media (max-width: 1024px) {
			.sdg-grid {
				grid-template-columns: repeat(2, 1fr);
			}
		}
		@media (max-width: 600px) {
			.sdg-grid {
				grid-template-columns: 1fr;
			}
		}
		.sdg-card {
			background: #ffffff;
			border-radius: 20px;
			padding: 40px 24px;
			box-shadow: 0 4px 24px rgba(0,0,0,0.03);
			border: 1px solid rgba(0,0,0,0.04);
			display: flex;
			flex-direction: column;
			align-items: center;
			text-align: center;
			transition: transform 0.3s;
		}
		.sdg-card:hover {
			transform: translateY(-4px);
			box-shadow: 0 12px 32px rgba(0,0,0,0.06);
		}
		.sdg-icon {
			width: 64px;
			height: 64px;
			border-radius: 16px;
			display: flex;
			align-items: center;
			justify-content: center;
			margin-bottom: 24px;
		}
		.sdg-tag {
			font-size: 0.95rem;
			font-weight: 800;
			margin-bottom: 12px;
		}
		.sdg-card-title {
			font-size: 1.25rem;
			font-weight: 700;
			color: #1f2937;
			margin-bottom: 16px;
			min-height: 56px;
		}
		.sdg-card-text {
			font-size: 0.95rem;
			color: #6b7280;
			line-height: 1.6;
		}
		.sdg-card.c-orange .sdg-icon {
			background: #ffedd5;
			color: #ea580c;
		}
		.sdg-card.c-orange .sdg-tag {
			color: #ea580c;
		}
		.sdg-card.c-yellow .sdg-icon {
			background: #fef9c3;
			color: #ca8a04;
		}
		.sdg-card.c-yellow .sdg-tag {
			color: #ca8a04;
		}
		.sdg-card.c-green .sdg-icon {
			background: #dcfce7;
			color: #16a34a;
		}
		.sdg-card.c-green .sdg-tag {
			color: #16a34a;
		}
		/* Features Section */
		.features-section {
			width: 100%;
			max-width: 1200px;
			margin: 0 auto;
			padding: 60px 0 30px 0;
			text-align: center;
		}
		.features-headline {
			font-size: 3rem;
			font-weight: 700;
			text-align: center;
			margin: 0 0 0.2em 0;
			line-height: 1.05;
			background: linear-gradient(90deg,#222 60%,#219150 80%,#f6c941 100%);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
			text-fill-color: transparent;
		}
		.features-headline span {
			color: #219150;
			background: none;
			-webkit-text-fill-color: #219150;
			text-fill-color: #219150;
		}
		.features-subtext {
			text-align: center;
			color: #444;
			font-size: 1.2rem;
			margin: 18px 0 38px 0;
			max-width: 700px;
			margin-left: auto;
			margin-right: auto;
		}
		.features-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
			gap: 32px;
			margin: 0 auto;
			max-width: 1100px;
			width: 100%;
			padding: 0 12px;
		}
		.feature-card {
			border-radius: 24px;
			box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
			padding: 40px 32px;
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			min-height: 250px;
			transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
			position: relative;
			overflow: hidden;
			border: 1px solid rgba(0,0,0,0.01);
		}
		.feature-card:hover {
			box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
			transform: translateY(-4px);
		}
		.feature-card.green-theme { background: linear-gradient(145deg, #f7fbf8, #eef7ef); }
		.feature-card.yellow-theme { background: linear-gradient(145deg, #fffef7, #fff9e6); }

		.feature-icon {
			width: 52px;
			height: 52px;
			border-radius: 14px;
			margin-bottom: 24px;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 1.6rem;
		}
		.green-theme .feature-icon { background: #dcece0; color: #219150; }
		.yellow-theme .feature-icon { background: #fff1d1; color: #d4a51d; }

		.features-grid-sync {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 32px;
			max-width: 1280px;
			width: 100%;
			margin: 0 auto;
			padding: 0 0 20px 0;
		}
		@media (max-width: 900px) {
			.features-grid-sync { grid-template-columns: 1fr; gap: 24px; }
		}
		.feature-title {
			font-size: 1.18rem;
			font-weight: 700;
			margin-bottom: 8px;
			color: #222;
			letter-spacing: -0.5px;
		}
		.feature-desc {
			color: #444;
			font-size: 1.01rem;
			line-height: 1.6;
		}
		@media (max-width: 900px) {
			.navbar {
				padding: 12px 16px;
				flex-wrap: wrap;
				position: sticky;
			}

			.navbar-toggle {
				display: inline-flex;
			}

			.navbar-menu,
			.navbar-action {
				display: none;
				width: 100%;
			}

			.navbar-menu {
				display: none;
				width: 100%;
				flex-direction: column;
				gap: 8px;
				padding: 16px 0;
			}

			.navbar-menu a {
				display: block;
				padding: 12px;
				border-radius: 10px;
				background: rgba(255, 255, 255, 0.62);
				text-align: center;
			}

			.navbar.is-open .navbar-menu {
				display: flex;
			}

			.hero {
				padding: 64px 0 44px 0;
			}

			.hero-title {
				font-size: clamp(1.75rem, 7vw, 2.4rem);
				line-height: 1.08;
				padding: 0 8px;
			}

			.hero-desc {
				font-size: 0.95rem;
				padding: 0 14px;
				margin-bottom: 24px;
			}

			.hero-btn {
				padding: 12px 22px;
				font-size: 0.95rem;
				margin-bottom: 22px;
			}

			.stats-row {
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 14px;
				margin-top: 34px;
				padding: 0 8px 12px;
			}

			.stat-box {
				padding: 16px 12px;
				border-radius: 14px;
			}

			.section-title,
			.why-title,
			.how-title,
			.tech-title,
			.built-title,
			.sdg-title {
				font-size: clamp(1.55rem, 6vw, 2rem) !important;
			}

			.features-subtext,
			.why-subtitle,
			.how-subtitle,
			.tech-subtitle,
			.built-desc,
			.sdg-subtitle {
				font-size: 0.95rem;
				margin-bottom: 22px;
			}

			.features-grid,
			.features-grid-sync,
			.why-grid,
			.how-grid,
			.tech-grid,
			.built-stats-grid,
			.sdg-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 14px;
			}
			.built-container {
				grid-template-columns: 1fr;
				gap: 32px;
			}

			.feature-card,
			.tech-card,
			.how-card,
			.why-card,
			.sdg-card,
			.b-stat-card {
				padding: 16px 12px;
				border-radius: 16px;
			}

			.tech-card {
				flex-direction: column;
				gap: 18px;
			}

			.built-section,
			.tech-section,
			.sdg-section {
				padding-left: 16px;
				padding-right: 16px;
			}

			.feature-icon,
			.how-icon-box,
			.tech-icon-box,
			.sdg-icon,
			.b-feat-icon {
				width: 42px;
				height: 42px;
				border-radius: 12px;
			}

			.feature-title,
			.tech-card-title,
			.how-card-title,
			.b-feat-title,
			.sdg-card-title {
				font-size: 0.98rem;
				min-height: 0;
				margin-bottom: 8px;
			}

			.feature-desc,
			.tech-card-text,
			.how-card-text,
			.b-feat-text,
			.sdg-card-text,
			.why-text {
				font-size: 0.88rem;
				line-height: 1.45;
			}

			#backToTop,
			#scrollTopBtn {
				right: 16px;
				bottom: 16px;
			}
		}
		@media (max-width: 600px) {
			.main-section {
				padding-left: 10px;
				padding-right: 10px;
			}

			.navbar {
				padding: 10px 12px;
			}

			.navbar-logo {
				font-size: 1.05rem;
			}

			.navbar-toggle {
				width: 42px;
				height: 42px;
			}

			.hero-badge {
				font-size: 0.72rem;
				padding: 5px 11px;
			}

			.hero-title {
				font-size: 1.55rem;
			}

			.hero-desc {
				font-size: 0.9rem;
				padding: 0 10px;
			}

			.stats-row,
			.features-grid,
			.features-grid-sync,
			.why-grid,
			.how-grid,
			.tech-grid,
			.built-stats-grid,
			.sdg-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 12px;
			}

			.feature-card,
			.tech-card,
			.how-card,
			.why-card,
			.sdg-card,
			.b-stat-card,
			.stat-box {
				padding: 14px 10px;
			}

			.features-headline,
			.why-title,
			.how-title,
			.tech-title,
			.built-title,
			.sdg-title {
				font-size: 1.4rem;
			}

			.features-subtext,
			.why-subtitle,
			.how-subtitle,
			.tech-subtitle,
			.built-desc,
			.sdg-subtitle {
				font-size: 0.88rem;
			}

			.feature-desc,
			.tech-card-text,
			.how-card-text,
			.b-feat-text,
			.sdg-card-text,
			.why-text,
			.stat-label {
				font-size: 0.82rem;
			}

			.stat-value, .b-stat-value {
				font-size: 1.55rem;
			}

			.faq-question {
				padding: 14px 14px;
				font-size: 0.92rem;
			}

			.faq-answer {
				padding: 0 14px 14px 14px;
				font-size: 0.88rem;
			}

			body {
				background-attachment: scroll;
			}
		}
		@media (prefers-reduced-motion: reduce) {
			*, *::before, *::after {
				animation-duration: 0.01ms !important;
				animation-iteration-count: 1 !important;
				transition-duration: 0.01ms !important;
				scroll-behavior: auto !important;
			}
		}
	</style>
	<script>
	// Scroll to top button logic
	window.addEventListener('scroll', function() {
		const btn = document.getElementById('scrollTopBtn');
		if (window.scrollY > 300) {
			btn.style.display = 'block';
		} else {
			btn.style.display = 'none';
		}
	});
	function scrollToTop() {
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	document.addEventListener('DOMContentLoaded', function() {
		const navbar = document.querySelector('.navbar');
		const navbarToggle = document.getElementById('navbarToggle');
		if (navbar && navbarToggle) {
			navbarToggle.addEventListener('click', function() {
				navbar.classList.toggle('is-open');
				navbarToggle.setAttribute('aria-expanded', navbar.classList.contains('is-open') ? 'true' : 'false');
			});

			navbar.querySelectorAll('a').forEach(function(link) {
				link.addEventListener('click', function() {
					if (window.innerWidth <= 900) {
						navbar.classList.remove('is-open');
						navbarToggle.setAttribute('aria-expanded', 'false');
					}
				});
			});
		}
	});
	</script>
</head>
<body>
	<a id="home"></a>
	<nav class="navbar">
		<div class="navbar-logo">
			<img src="agricorn.png" alt="" style="height: 36px; width: auto;">Agri<span>Corn</span>
		</div>
		<button class="navbar-toggle" id="navbarToggle" type="button" aria-label="Toggle navigation" aria-expanded="false">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"></line><line x1="4" y1="12" x2="20" y2="12"></line><line x1="4" y1="18" x2="20" y2="18"></line></svg>
		</button>
		<div class="navbar-menu">
			<a href="#home">Home</a>
			<a href="#features">Features</a>
			<a href="#why-us">Why Us</a>
			<a href="#how-it-works">How It Works</a>
			<a href="#tech">Technology</a>
			<a href="#built">Built For Farmers</a>
			<a href="#sdg">SDG</a>
			<a href="#faq">FAQ</a>
		</div>
		<div class="navbar-actions">
			<a href="login.php" class="navbar-btn">Login</a>
		</div>
	</nav>
	<section class="hero main-section">
		<div class="hero-badge">
			<svg viewBox="0 0 24 24"><path d="M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25L19 9zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12l-5.5-2.5zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25L19 15z"/></svg>
			AI-Powered Farming Platform
		</div>
		<h1 class="hero-title">Experience <span>AgriCorn</span></h1>
		<div class="hero-desc">Transform corn farming in Calatagan through intelligent planning, crop monitoring, and data-driven support. From seed to harvest, AgriCorn Planner helps local farmers grow smarter.</div>
		<a href="login.php" class="hero-btn">Get Started</a>
		<div class="stats-row reveal">
			<div class="stat-box">
				<svg class="stat-icon" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
				<div class="stat-value">50+</div>
				<div class="stat-label">Active Farmers</div>
			</div>
			<div class="stat-box">
				<svg class="stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
				<div class="stat-value">25</div>
				<div class="stat-label">Barangay of Calatagan</div>
			</div>
			<div class="stat-box">
				<svg class="stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M12 2c-1.5 0-3 1.5-3 4s.5 6 3 10c2.5-4 3-7.5 3-10s-1.5-4-3-4z"></path>
					<path d="M9 6c-1 0-2 1-2 3s.5 4 2 6"></path>
					<path d="M15 6c1 0 2 1 2 3s-.5 4-2 6"></path>
					<path d="M12 16v6"></path>
				</svg>
				<div class="stat-value">10+</div>
				<div class="stat-label">Corn Varieties</div>
			</div>
			<div class="stat-box">
				<svg class="stat-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
				<div class="stat-value">24/7</div>
				<div class="stat-label">Support</div>
			</div>
		</div>
	</section>

		   <!-- Features Section -->
		   <section class="features-section main-section reveal" id="features" style="background: transparent;">
			   <div style="text-align:center;">
				   <h2 class="section-title">
					   Powerful Features
				   </h2>
				   <div style="color:#000;font-size:1.25rem;margin:18px 0 38px 0;max-width:700px;margin-left:auto;margin-right:auto;">
					   Everything farmers need in one platform.
				   </div>
				<div class="features-grid-sync">
			   <div class="feature-card green-theme" style="align-items:center;text-align:center;">
				   <div class="feature-icon" style="display:flex;align-items:center;justify-content:center;">
				   		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1" ry="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
				   </div>
				   <div style="font-size:1.18rem;font-weight:700;margin-bottom:8px;color:#222;">Corn Planting Profile</div>
				   <div style="color:#444;font-size:1.01rem;line-height:1.6;">Manage planting data, varieties, costs, and farm records.</div>
			   </div>
			   <div class="feature-card yellow-theme" style="align-items:center;text-align:center;">
				   <div class="feature-icon" style="display:flex;align-items:center;justify-content:center;">
				   		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
				   </div>
				   <div style="font-size:1.18rem;font-weight:700;margin-bottom:8px;color:#222;">Lifecycle Stage Tracker</div>
				   <div style="color:#444;font-size:1.01rem;line-height:1.6;">Monitor corn growth from seedling to harvest.</div>
			   </div>
			   <div class="feature-card green-theme" style="align-items:center;text-align:center;">
				   <div class="feature-icon" style="display:flex;align-items:center;justify-content:center;">
				   		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="14"/></svg>
				   </div>
				   <div style="font-size:1.18rem;font-weight:700;margin-bottom:8px;color:#222;">Corn Care Calendar</div>
				   <div style="color:#444;font-size:1.01rem;line-height:1.6;">Track watering, fertilizer, and scheduled farm tasks.</div>
			   </div>
			   <div class="feature-card yellow-theme" style="align-items:center;text-align:center;">
				   <div class="feature-icon" style="display:flex;align-items:center;justify-content:center;">
				   		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="15" y2="11"/></svg>
				   </div>
				   <div style="font-size:1.18rem;font-weight:700;margin-bottom:8px;color:#222;">Corn Farming Guide</div>
				   <div style="color:#444;font-size:1.01rem;line-height:1.6;">Access practical guidance for corn production.</div>
			   </div>
			   <div class="feature-card green-theme" style="align-items:center;text-align:center;">
				   <div class="feature-icon" style="display:flex;align-items:center;justify-content:center;">
				   		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/><path d="M11 8v6"/><path d="M8 11h6"/></svg>
				   </div>
				   <div style="font-size:1.18rem;font-weight:700;margin-bottom:8px;color:#222;">Pest and Disease Identification</div>
				   <div style="color:#444;font-size:1.01rem;line-height:1.6;">Detect possible pest or disease issues with recommendations.</div>
			   </div>
			   <div class="feature-card yellow-theme" style="align-items:center;text-align:center;">
				   <div class="feature-icon" style="display:flex;align-items:center;justify-content:center;">
				   		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
				   </div>
				   <div style="font-size:1.18rem;font-weight:700;margin-bottom:8px;color:#222;">Machine Learning Growth Prediction</div>
				   <div style="color:#444;font-size:1.01rem;line-height:1.6;">Estimate crop growth progress and harvest window.</div>
			   </div>
			</div>	</div>
			</div>
		</section>
		   <div class="section-divider"></div>

		   <!-- Why Choose Section -->
			<section class="main-section reveal" id="why-us" style="background: transparent; padding-top: 0; padding-bottom: 0;">
				<div class="why-section-container">
					<h2 class="why-title">Why Choose <span>AgriCorn Planner</span>?</h2>
					<div class="why-subtitle">Designed to support local corn farmers in Calatagan</div>
					
					<div class="why-grid">
						<div class="why-card">
							<svg class="why-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17H5a2 2 0 0 0-2 2v0a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v0a2 2 0 0 0-2-2h-4"/><rect x="9" y="3" width="6" height="14" rx="1"/></svg>
							<div class="why-text">Improve decision-making through recorded farm data</div>
						</div>
						<div class="why-card">
							<svg class="why-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
							<div class="why-text">Reduce crop risk through early pest monitoring</div>
						</div>
						<div class="why-card">
							<svg class="why-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
							<div class="why-text">Track farm activities using automated schedules</div>
						</div>
						<div class="why-card">
							<svg class="why-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
							<div class="why-text">Support smarter corn production in Calatagan</div>
						</div>
					</div>
					
					</div>
			</section>

		   <!-- How It Works Section -->
			<section class="main-section how-section reveal" id="how-it-works" style="background: transparent;">
				<h2 class="how-title">How It Works</h2>
				<div class="how-subtitle">Get started with AgriCorn Planner in three simple steps</div>
				
				<div class="how-grid">
					<div class="how-card">
						<div class="how-step-num green">1</div>
						<div class="how-icon-box green">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
						</div>
						<div class="how-card-title">Create Your Profile</div>
						<div class="how-card-text">Sign up and set up your farm profile with details about your fields, corn varieties, and farming goals.</div>
					</div>
					
					<div class="how-card">
						<div class="how-step-num yellow">2</div>
						<div class="how-icon-box yellow">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"></path><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"></path></svg>
						</div>
						<div class="how-card-title">Track Your Crops</div>
						<div class="how-card-text">Monitor corn growth stages in real-time with visual dashboards and receive AI-powered care recommendations.</div>
					</div>
					
					<div class="how-card">
						<div class="how-step-num green">3</div>
						<div class="how-icon-box green">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>
						</div>
						<div class="how-card-title">Harvest Success</div>
						<div class="how-card-text">Optimize harvest timing with ML predictions and achieve better yields through data-driven farming decisions.</div>
					</div>
				</div>
			</section>

	<!-- Advanced Technology Section -->
	<section class="tech-section reveal" id="tech">
		<div class="tech-container">
			<h2 class="tech-title">Advanced Technology for <span>Modern Farmers</span></h2>
			<div class="tech-subtitle">Cutting-edge tools designed to give you a competitive advantage</div>
			
			<div class="tech-grid">
				<!-- Card 1 -->
				<div class="tech-card">
					<div class="tech-icon-box yellow">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:28px;height:28px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
					</div>
					<div class="tech-card-content">
						<div class="tech-card-title">Pest & Disease Database</div>
						<div class="tech-card-text">Comprehensive library of corn pests and diseases with identification guides and treatment protocols.</div>
						<ul class="tech-list">
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Image recognition AI</li>
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Treatment recommendations</li>
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Prevention strategies</li>
						</ul>
					</div>
				</div>
				
				<!-- Card 2 -->
				<div class="tech-card">
					<div class="tech-icon-box green">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:28px;height:28px;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
					</div>
					<div class="tech-card-content">
						<div class="tech-card-title">Smart Notifications</div>
						<div class="tech-card-text">Stay informed with intelligent alerts for critical farming tasks, weather changes, and growth milestones.</div>
						<ul class="tech-list">
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Task reminders</li>
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Growth stage updates</li>
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Critical alerts</li>
						</ul>
					</div>
				</div>
				
				<!-- Card 3 -->
				<div class="tech-card">
					<div class="tech-icon-box yellow">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:28px;height:28px;"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
					</div>
					<div class="tech-card-content">
						<div class="tech-card-title">Yield Optimization</div>
						<div class="tech-card-text">Maximize your harvest with data-driven insights on planting density, fertilization, and resource allocation.</div>
						<ul class="tech-list">
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Resource planning</li>
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Data-driven insights</li>
							<li><svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg> Harvest timing</li>
						</ul>
					</div>
				</div>
			</div>
		</div>
	</section>

	<!-- Built For Farmers Section -->
	<section class="built-section reveal" id="built">
		<h2 class="built-title">Built for <span>Corn Farmers in Calatagan</span></h2>
		<div class="built-desc">AgriCorn Planner combines practical farming knowledge and digital tools to support local corn production.</div>
		
		<div class="built-container">
			<!-- Left Content -->
			<div>
				
				<div class="built-features">
					<div class="b-feat-item">
						<div class="b-feat-icon green">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:24px;height:24px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
						</div>
						<div>
							<div class="b-feat-title">🌱 Save Time and Effort</div>
							<div class="b-feat-text">Automate record keeping and monitoring.</div>
						</div>
					</div>
					<div class="b-feat-item">
						<div class="b-feat-icon yellow">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:24px;height:24px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
						</div>
						<div>
							<div class="b-feat-title">💰 Improve Profitability</div>
							<div class="b-feat-text">Track expenses and production outcomes.</div>
						</div>
					</div>
					<div class="b-feat-item">
						<div class="b-feat-icon green">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:24px;height:24px;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
						</div>
						<div>
							<div class="b-feat-title">♻️ Support Sustainable Farming</div>
							<div class="b-feat-text">Encourage data-informed resource use.</div>
						</div>
					</div>
				</div>
			</div>
			
			<!-- Right Content (Stats Grid) -->
			<div class="built-stats-grid">
				<div class="b-stat-card green">
					<div class="b-stat-value"><?php echo $farmerCount; ?>+</div>
					<div class="b-stat-label">Registered Farmers</div>
				</div>
				<div class="b-stat-card yellow">
					<div class="b-stat-value">6</div>
					<div class="b-stat-label">Core Features</div>
				</div>
				<div class="b-stat-card yellow">
					<div class="b-stat-value">AI</div>
					<div class="b-stat-label">Powered Detection</div>
				</div>
				<div class="b-stat-card green">
					<div class="b-stat-value">100%</div>
					<div class="b-stat-label">Free to Use</div>
				</div>
			</div>
		</div>
	</section>

	<!-- SDG Section -->
	<section class="sdg-section reveal" id="sdg">
		<h2 class="sdg-title">Contributing to the <span>UN Sustainable Development Goals</span></h2>
		<div class="sdg-subtitle">AgriCorn Planner aligns with global sustainability initiatives to create a better future for farming</div>
		
		<div class="sdg-grid">
			<div class="sdg-card c-orange">
				<div class="sdg-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;"><path d="M4 19V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2h4a2 2 0 0 1 2 2v4h2a2 2 0 0 1 2 2v4"></path><path d="M2 19h20"></path></svg>
				</div>
				<div class="sdg-tag">SDG 9</div>
				<div class="sdg-card-title">Industry, Innovation &<br>Infrastructure</div>
				<div class="sdg-card-text">Promoting innovation in agriculture through AI-powered farming solutions and modern infrastructure</div>
			</div>
			
			<div class="sdg-card c-orange">
				<div class="sdg-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect><path d="M9 22v-4h6v4"></path><path d="M8 6h.01"></path><path d="M16 6h.01"></path><path d="M12 6h.01"></path><path d="M12 10h.01"></path><path d="M12 14h.01"></path><path d="M16 10h.01"></path><path d="M16 14h.01"></path><path d="M8 10h.01"></path><path d="M8 14h.01"></path></svg>
				</div>
				<div class="sdg-tag">SDG 11</div>
				<div class="sdg-card-title">Sustainable Cities &<br>Communities</div>
				<div class="sdg-card-text">Supporting sustainable food systems and strengthening rural farming communities</div>
			</div>
			
			<div class="sdg-card c-yellow">
				<div class="sdg-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
				</div>
				<div class="sdg-tag">SDG 12</div>
				<div class="sdg-card-title">Responsible Consumption<br>& Production</div>
				<div class="sdg-card-text">Optimizing resource usage and reducing agricultural waste through smart farming practices</div>
			</div>
			
			<div class="sdg-card c-green">
				<div class="sdg-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
				</div>
				<div class="sdg-tag">SDG 13</div>
				<div class="sdg-card-title">Climate Action</div>
				<div class="sdg-card-text">Empowering farmers with data-driven insights to adapt to climate change and reduce carbon footprint</div>
			</div>
		</div>
	</section>

		<!-- FAQ Section -->
					<section class="main-section reveal" id="faq" style="background: transparent; padding-top:32px;padding-bottom:32px;">
						<div style="max-width:1100px;margin:0 auto;">
							<h2 class="section-title">Frequently Asked Questions</h2>
							<div style="color:#444;font-size:1.13rem;margin-bottom:32px;max-width:900px;margin-left:auto;margin-right:auto;text-align:center;">Find answers to common questions about AgriCorn and how to get the most out of the platform.</div>
							<div class="faq-accordion">
								<div class="faq-item">
									<button class="faq-question">How do I sign up for AgriCorn Planner? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">Click the Get Started or Sign Up button, then enter your name, address, username, and password to create an account.</div>
								</div>
								<div class="faq-item">
									<button class="faq-question">Is AgriCorn Planner free to use? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">Yes, AgriCorn Planner is available for farmers to use for crop monitoring, planning, and guidance.</div>
								</div>
								<div class="faq-item">
									<button class="faq-question">How does the Corn Care Calendar work? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">After entering your planting date, the user will automatically generates a schedule for watering, fertilizer application, and monitoring tasks.</div>
								</div>
								<div class="faq-item">
									<button class="faq-question">How does Pest and Disease Identification work? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">Farmers can upload a photo or use the camera to detect possible pests or diseases and receive recommended actions.</div>
								</div>
								<div class="faq-item">
									<button class="faq-question">How accurate is the Machine Learning Growth Prediction? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">Predictions are based on recorded planting data and crop activities. Results are estimates and should be used as decision support.</div>
								</div>
								<div class="faq-item">
									<button class="faq-question">What happens if my crop is damaged by typhoon or flood? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">You can use the Void Planting Record option to stop monitoring the affected crop and start a new planting cycle.</div>
								</div>
								<div class="faq-item">
									<button class="faq-question">Can I compare estimated yield with actual harvest? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">Yes. The Summary feature allows farmers to review estimated yield versus actual harvest results.</div>
								</div>

								<div class="faq-item">
									<button class="faq-question">How do I start a new planting cycle after harvest? <span class="faq-arrow">&#9654;</span></button>
									<div class="faq-answer">After completing a crop cycle, use the Start New Cycle option to create a new planting record.</div>
								</div>
							</div>
						</div>
					</section>
				<div class="section-divider"></div>
											<!-- Footer -->
																	<footer class="site-footer" role="contentinfo" style="padding:48px 0;border-top:1px solid rgba(127, 182, 133, 0.3);background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);color:#1f2937;">
																		<div style="max-width:1200px;margin:0 auto;padding:0 18px;text-align:center;">
																			<div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
																				<img src="agricorn.png" alt="AgriCorn logo" style="height:36px;width:auto;">
																				<div style="font-weight:700;color:#16a34a;font-size:1.05rem;">Agri<span style="color:#f6c941;">Corn</span></div>
																			</div>
																			<p style="margin:8px 0 0 0;color:#374151;line-height:1.4;">Empowering farmers with practical, data-driven tools for corn production.</p>
																			<div style="margin-top:8px;color:#374151;font-size:0.95rem;">&copy; <?php echo date('Y'); ?> AgriCorn. All rights reserved.</div>
																		</div>
																	</footer>
	<!-- Back To Top Button -->
	<button id="backToTop" aria-label="Back to top">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:24px;height:24px;"><polyline points="18 15 12 9 6 15"></polyline></svg>
	</button>
</body>
</html>
